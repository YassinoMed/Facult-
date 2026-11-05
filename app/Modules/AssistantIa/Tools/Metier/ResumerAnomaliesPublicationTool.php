<?php

namespace App\Modules\AssistantIa\Tools\Metier;

use App\Models\User;
use App\Modules\AssistantIa\Contracts\OutilIaInterface;
use App\Modules\AssistantIa\DTO\ResultatOutil;
use App\Modules\AssistantIa\Exceptions\SecuriteIaException;
use App\Modules\AssistantIa\Services\FiltreSecuriteIa;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Services\CorrespondanceExamenService;
use App\Modules\Evaluation\Services\ExamGouvernanceValidationService;

/**
 * Outil métier AssistantIa : Synthèse des anomalies bloquant la publication officielle d'un examen.
 * Identifie les validations manquantes, incohérences de correspondance et déficits de salles.
 */
class ResumerAnomaliesPublicationTool implements OutilIaInterface
{
    public function __construct(
        protected ?CorrespondanceExamenService $serviceCorrespondance = null,
        protected ?ExamGouvernanceValidationService $serviceGouvernance = null
    ) {
        $this->serviceCorrespondance = $serviceCorrespondance ?? app(CorrespondanceExamenService::class);
        $this->serviceGouvernance = $serviceGouvernance ?? app(ExamGouvernanceValidationService::class);
    }

    public function nom(): string
    {
        return 'resumer_anomalies_publication';
    }

    public function description(): string
    {
        return 'Résume les anomalies et blocages bloquant la publication officielle d\'un examen ou de ses épreuves.';
    }

    public function mode(): string
    {
        return 'metier';
    }

    public function permissionRequise(): string
    {
        return 'assistant_ia.mode_metier';
    }

    public function schemaParametres(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'examen_id' => [
                    'type' => 'integer',
                    'description' => 'Identifiant numérique de l\'examen à analyser',
                ],
            ],
            'required' => ['examen_id'],
        ];
    }

    public function estSensible(): bool
    {
        return false;
    }

    public function executer(array $parametres, User $utilisateur): ResultatOutil
    {
        $examenId = (int) ($parametres['examen_id'] ?? 0);
        if ($examenId <= 0) {
            return ResultatOutil::echec("L'identifiant de l'examen est obligatoire et doit être un entier positif.");
        }

        $examen = Examen::with(['programme', 'session', 'epreuves.matiere', 'epreuves.salle'])->find($examenId);
        if (! $examen) {
            return ResultatOutil::echec("Examen #{$examenId} introuvable.");
        }

        // Contrôle d'habilitation et BOLA facultaire
        if (! $utilisateur->hasPermission('assistant_ia.mode_metier') && ! $utilisateur->estSuperAdmin()) {
            throw new SecuriteIaException("Vous ne disposez pas de l'habilitation requise pour auditer la publication d'examen.");
        }

        $faculteRestreinte = $utilisateur->faculteRestreinteId();
        $faculteExamen = $examen->programme?->faculte_id ?? $examen->faculte_id;

        if ($faculteRestreinte !== null && $faculteExamen !== null) {
            FiltreSecuriteIa::verifierPerimetreFaculte($utilisateur, $faculteExamen);
        }

        $bloquants = [];
        $avertissements = [];
        $etapesRequises = [];

        // 1. Analyse via CorrespondanceExamenService sur chaque épreuve de l'examen
        foreach ($examen->epreuves as $epreuve) {
            $analyse = $this->serviceCorrespondance->analyserEpreuve($epreuve);
            foreach ($analyse['bloquants'] ?? [] as $b) {
                $bloquants[] = "[Épreuve #{$epreuve->id}] {$b}";
            }

            if ($epreuve->salle === null) {
                $avertissements[] = "Épreuve #{$epreuve->id} ({$epreuve->matiere?->nom}) : aucune salle d'examen assignée.";
                $etapesRequises['affectation_salle'] = 'Attribuer une salle ou un amphi via le module D-04.';
            }

            if ($epreuve->population_validated_at === null) {
                $bloquants[] = "Épreuve #{$epreuve->id} : la population d'étudiants n'a pas été validée.";
                $etapesRequises['validation_population'] = 'Valider la cohorte d\'étudiants éligibles dans la préparation de l\'examen.';
            }
        }

        // 3. Statut de gouvernance
        if ($examen->statut === 'published') {
            $resume = "Examen #{$examenId} ({$examen->titre}) : Déjà publié officiellement.";
        } else {
            $nbBloquants = count($bloquants);
            $nbAvertissements = count($avertissements);

            if ($nbBloquants > 0) {
                $resume = "Examen #{$examenId} ({$examen->titre}) : Publication actuellement BLOQUÉE par {$nbBloquants} anomalie(s) majeure(s).";
            } elseif ($nbAvertissements > 0) {
                $resume = "Examen #{$examenId} ({$examen->titre}) : Publiable sous réserve ({$nbAvertissements} avertissement(s) de ressources).";
            } else {
                $resume = "Examen #{$examenId} ({$examen->titre}) : Conforme à 100%, prêt pour publication officielle.";
            }
        }

        $estPubliable = $bloquants === [] && $examen->statut !== 'published';

        $donneesSortie = [
            'examen_id' => $examenId,
            'titre' => $examen->titre ?? 'Examen #'.$examenId,
            'statut_actuel' => $examen->statut,
            'est_publiable' => $estPubliable,
            'total_bloquants' => count($bloquants),
            'total_avertissements' => count($avertissements),
            'anomalies_bloquantes' => array_values(array_unique($bloquants)),
            'avertissements' => array_values(array_unique($avertissements)),
            'plan_deblocage_recommande' => array_values($etapesRequises),
        ];

        $sources = [
            [
                'titre' => 'Service de Correspondance des Examens',
                'url_ou_chemin' => 'app/Modules/Evaluation/Services/CorrespondanceExamenService.php',
                'source' => 'business_procedure',
            ],
            [
                'titre' => 'Gouvernance et Circuit d\'Approbation des Examens UniRegistre',
                'url_ou_chemin' => 'docs/regles-metier/deliberations-examens.md',
                'source' => 'business_procedure',
            ],
        ];

        return ResultatOutil::succes($donneesSortie, $resume, $sources);
    }
}
