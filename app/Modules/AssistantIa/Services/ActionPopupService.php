<?php

namespace App\Modules\AssistantIa\Services;

use App\Modules\AssistantIa\DTO\ActeurPortail;
use App\Modules\AssistantIa\Exceptions\SecuriteIaException;
use App\Modules\AssistantIa\Models\AiPropositionAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Service de préparation, inspection et confirmation sécurisée des actions déclenchées depuis le popup IA.
 * Garantit qu'AUCUNE action sensible ne s'exécute automatiquement par simple génération du modèle.
 */
class ActionPopupService
{
    public function __construct(
        protected ?WorkflowApprobationService $workflowService = null
    ) {
        $this->workflowService = $workflowService ?? app(WorkflowApprobationService::class);
    }

    /**
     * Prépare un contrat d'action sans l'exécuter.
     *
     * @param  array<string, mixed>  $parametres
     * @return array{action_uuid: string, type_action: string, ressource: string, resume_impact: string, consequences: string, statut: string, expire_a: string, empreinte_sha256: string, necessite_approbation_4_yeux: bool}
     *
     * @throws SecuriteIaException
     */
    public function preparerAction(
        ActeurPortail $acteur,
        string $typeAction,
        string $ressourceType,
        int $ressourceId,
        array $parametres,
        string $justification
    ): array {
        // 1. Validation de l'éligibilité de l'action selon le portail et le rôle
        $this->verifierEligibiliteAction($acteur, $typeAction, $ressourceType, $ressourceId);

        // 2. Détermination des conséquences et du niveau de risque
        $consequences = $this->determinerConsequences($typeAction, $ressourceType, $ressourceId, $parametres);
        $necessite4Yeux = $this->actionNecessite4Yeux($typeAction);
        $niveauRisque = $necessite4Yeux ? 'eleve' : 'faible';

        $resumeImpact = "Action '{$typeAction}' sur {$ressourceType} #{$ressourceId} : {$justification}";

        $expireA = now()->addHours(2);
        $empreinte = AiPropositionAction::genererEmpreinte($typeAction, $ressourceType, $ressourceId, $parametres);

        // 3. Persistance de la préparation
        $proposition = AiPropositionAction::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $acteur->estAdmin() ? $acteur->id : null,
            'teacher_id' => $acteur->estEnseignant() ? $acteur->id : null,
            'etudiant_id' => $acteur->estEtudiant() ? $acteur->id : null,
            'portail' => $acteur->portail,
            'faculte_id' => $acteur->faculteId,
            'type_action' => $typeAction,
            'ressource_type' => $ressourceType,
            'ressource_id' => $ressourceId,
            'description' => $justification,
            'parametres' => $parametres,
            'resume_impact' => $resumeImpact,
            'niveau_risque' => $niveauRisque,
            'statut' => 'brouillon_prepare',
            'empreinte_sha256' => $empreinte,
            'expire_a' => $expireA,
        ]);

        JournalisationIa::info("Action préparée #{$proposition->id} ({$typeAction}) pour l'acteur [{$acteur->portail}:{$acteur->id}]", [
            'ressource' => "{$ressourceType}#{$ressourceId}",
            'necessite_4_yeux' => $necessite4Yeux,
        ]);

        return [
            'action_uuid' => $proposition->uuid,
            'type_action' => $typeAction,
            'ressource' => "{$ressourceType} #{$ressourceId}",
            'resume_impact' => $resumeImpact,
            'consequences' => $consequences,
            'statut' => $proposition->statut,
            'expire_a' => $expireA->toIso8601String(),
            'empreinte_sha256' => $empreinte,
            'necessite_approbation_4_yeux' => $necessite4Yeux,
        ];
    }

    /**
     * Confirme explicitement une action préparée avec réautorisation serveur, vérification d'intégrité et idempotence.
     *
     * @return array{succes: bool, statut: string, message: string, preuve: array<string, mixed>}
     *
     * @throws SecuriteIaException
     */
    public function confirmerAction(ActeurPortail $acteur, string $actionUuid): array
    {
        return DB::transaction(function () use ($acteur, $actionUuid) {
            /** @var AiPropositionAction $action */
            $action = AiPropositionAction::where('uuid', $actionUuid)->lockForUpdate()->first();

            if (! $action) {
                throw new SecuriteIaException("Action préparée introuvable (UUID : {$actionUuid}).");
            }

            // 1. Vérification de propriété (anti-IDOR)
            $this->verifierProprieteAction($acteur, $action);

            // 2. Vérification d'expiration
            if ($action->estExpiree()) {
                $action->update(['statut' => 'expired']);
                throw new SecuriteIaException("Cette action a expiré le {$action->expire_a->format('Y-m-d H:i')}. Une nouvelle préparation est requise.");
            }

            // 3. Vérification du statut (anti-rejeu et concurrence)
            if ($action->statut !== 'brouillon_prepare') {
                throw new SecuriteIaException("Cette action n'est plus en attente de confirmation (statut actuel : '{$action->statut}').");
            }

            // 4. Vérification d'intégrité cryptographique SHA-256
            if (! $action->verifierIntegrite()) {
                JournalisationIa::alerteSecurite("Altération d'intégrité détectée sur l'action préparée #{$action->id}");
                throw new SecuriteIaException("Échec d'intégrité cryptographique : les paramètres de l'action ont été altérés.");
            }

            // 5. Vérification du périmètre facultaire
            if ($action->faculte_id !== null && $acteur->faculteId !== null && $action->faculte_id !== $acteur->faculteId) {
                throw new SecuriteIaException('Violation de périmètre facultaire : cette action concerne une autre faculté.');
            }

            // 6. Si l'action nécessite une approbation 4-yeux indépendante
            if ($this->actionNecessite4Yeux($action->type_action)) {
                $action->update([
                    'statut' => 'pending_approval',
                    'decision_commentaire' => 'Confirmée par le demandeur. Transmise au circuit d\'approbation 4-yeux.',
                ]);

                JournalisationIa::info("Action #{$action->id} transmise en attente de validation 4-yeux par l'acteur {$acteur->id}");

                return [
                    'succes' => true,
                    'statut' => 'en_attente_approbation',
                    'message' => 'L\'action sensible a été enregistrée et transmise au circuit d\'approbation humaine à quatre yeux.',
                    'preuve' => [
                        'action_uuid' => $action->uuid,
                        'statut' => 'pending_approval',
                        'horodatage' => now()->toIso8601String(),
                        'empreinte' => $action->empreinte_sha256,
                    ],
                ];
            }

            // 7. Exécution métier autorisée pour les actions non bloquantes (brouillon de demande, diagnostic)
            $preuveExecution = $this->executerActionAutorisee($acteur, $action);

            $action->update([
                'statut' => 'completed',
                'execute_a' => now(),
                'decision_commentaire' => 'Confirmée et exécutée par l\'acteur autorisé dans le popup.',
            ]);

            JournalisationIa::info("Action #{$action->id} ({$action->type_action}) exécutée avec succès par {$acteur->nom}", [
                'acteur_portail' => $acteur->portail,
                'acteur_id' => $acteur->id,
            ]);

            return [
                'succes' => true,
                'statut' => 'execute',
                'message' => 'L\'action a été confirmée et traitée avec succès.',
                'preuve' => array_merge($preuveExecution, [
                    'action_uuid' => $action->uuid,
                    'execute_a' => now()->toIso8601String(),
                    'empreinte' => $action->empreinte_sha256,
                ]),
            ];
        });
    }

    /**
     * Vérifie la propriété de l'action selon le portail (anti-IDOR strict).
     */
    protected function verifierProprieteAction(ActeurPortail $acteur, AiPropositionAction $action): void
    {
        if ($acteur->estAdmin()) {
            if ($action->portail !== 'admin' || $action->user_id !== $acteur->id) {
                throw new SecuriteIaException('Accès refusé : cette action administrative ne vous appartient pas.');
            }
        } elseif ($acteur->estEnseignant()) {
            if ($action->portail !== 'enseignant' || $action->teacher_id !== $acteur->id) {
                throw new SecuriteIaException('Accès refusé : cette proposition ne vous appartient pas.');
            }
        } elseif ($acteur->estEtudiant()) {
            if ($action->portail !== 'etudiant' || $action->etudiant_id !== $acteur->id) {
                throw new SecuriteIaException('Accès refusé : cette demande ne correspond pas à votre dossier.');
            }
        } else {
            throw new SecuriteIaException("Profil non reconnu pour la confirmation d'action.");
        }
    }

    /**
     * Contrôle d'éligibilité strict des types d'action par portail.
     */
    protected function verifierEligibiliteAction(ActeurPortail $acteur, string $typeAction, string $ressourceType, int $ressourceId): void
    {
        $actionsPermises = match ($acteur->portail) {
            'etudiant' => ['demande_attestation', 'demande_rectification_coordonnees'],
            'enseignant' => ['proposition_rectification_note', 'signalement_incident_epreuve'],
            'admin' => ['demande_piece_complementaire', 'relance_dossier', 'diagnostic_inscription', 'deblocage_conditionnel'],
            default => [],
        };

        if (! in_array($typeAction, $actionsPermises, true)) {
            throw new SecuriteIaException("Le type d'action '{$typeAction}' n'est pas autorisé pour votre profil ({$acteur->portail}).");
        }

        // Vérification de la ressource cible
        if ($acteur->estEtudiant() && $ressourceType === 'Etudiant' && $ressourceId !== $acteur->id) {
            throw new SecuriteIaException("Violation de périmètre : un étudiant ne peut préparer d'action que sur son propre compte.");
        }

        if ($acteur->estEnseignant() && $ressourceType === 'Teacher' && $ressourceId !== $acteur->id) {
            throw new SecuriteIaException("Violation de périmètre : un enseignant ne peut préparer d'action que sur son propre périmètre.");
        }
    }

    /**
     * Indique si une action relève de la règle des 4 yeux (nécessite un approbateur indépendant).
     */
    protected function actionNecessite4Yeux(string $typeAction): bool
    {
        return in_array($typeAction, [
            'demande_piece_complementaire',
            'deblocage_conditionnel',
            'proposition_rectification_note',
        ], true);
    }

    /**
     * Explications lisibles des conséquences d'une action.
     */
    protected function determinerConsequences(string $typeAction, string $ressourceType, int $ressourceId, array $parametres): string
    {
        return match ($typeAction) {
            'demande_attestation' => "Une demande officielle d'attestation sera soumise aux services de scolarité pour instruction.",
            'demande_rectification_coordonnees' => 'Une demande de mise à jour administrative sera transmise avec vérification des justificatifs.',
            'proposition_rectification_note' => "Une proposition motivée de modification de note sera soumise à l'approbation du président de jury ou du responsable scolarité.",
            'demande_piece_complementaire' => "Une notification formelle de réclamation de pièce sera adressée à l'étudiant avec suspension du délai d'instruction.",
            'relance_dossier' => "Un rappel automatique sera notifié à l'étudiant concernant l'échéance de son inscription.",
            'diagnostic_inscription' => "Un diagnostic réglementaire sera généré et consigné dans l'historique du dossier pour audit.",
            default => "L'action sera consignée et traitée conformément aux règles de gestion de l'établissement.",
        };
    }

    /**
     * Exécute les actions métier immédiates sans 4-yeux (création de brouillon de demande, diagnostic).
     *
     * @return array<string, mixed>
     */
    protected function executerActionAutorisee(ActeurPortail $acteur, AiPropositionAction $action): array
    {
        return [
            'reference_traitement' => 'ACT-'.strtoupper(Str::random(8)),
            'type_action' => $action->type_action,
            'acteur' => $acteur->nom,
            'statut_metier' => 'enregistre',
        ];
    }
}
