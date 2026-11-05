<?php

namespace App\Services\Acquis;

use App\Models\Etudiant;
use App\Models\HistoriqueAcademique;
use App\Models\Note;
use App\Models\User;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\ParcoursAcademique\Models\ParcoursPlan;

/**
 * LOOP 29 — Hub d'intégration transversale académique.
 *
 * Coordonne les flux entre Délibérations, Notes, Inscriptions, Documents et Notifications
 * conformément aux principes CORE + MODULES d'UniRegistre (découplage et cohérence stricte).
 */
class IntegrationAcademiqueHubService
{
    public function __construct(
        protected MoteurDecisionAcademiqueService $moteurDecision,
        protected PlanRedoublementService $planRedoublementService,
        protected NotificationRedoublementService $notificationService,
        protected DocumentPedagogiqueOfficielService $documentService,
        protected ReinscriptionPedagogiqueRedoublantService $reinscriptionService
    ) {}

    /**
     * Traite et synchronise les acquis suite à la publication officielle d'une décision de délibération.
     *
     * @return array<string, mixed>
     */
    public function synchroniserApresDeliberation(DecisionDeliberation $deliberation): array
    {
        $deliberation->loadMissing(['etudiant', 'inscriptionAcademique.programmeRef']);
        $etudiant = $deliberation->etudiant;

        if ($etudiant === null) {
            return [
                'succes' => false,
                'message' => 'Étudiant associé à la délibération introuvable.',
            ];
        }

        // Si la délibération n'est pas publiée, aucun effet sur le redoublement officiel
        if (! $deliberation->publie) {
            return [
                'succes' => false,
                'message' => 'La délibération n\'est pas encore officiellement publiée.',
                'decision' => $deliberation->decision,
            ];
        }

        // 1. Calcul des décisions d'acquis via le moteur central
        $decisionCentrale = $this->moteurDecision->determinerDecision(
            $etudiant,
            $deliberation->inscriptionAcademique?->annee_universitaire_id
        );

        $planGenere = null;

        // 2. Si redoublement autorisé, génération / mise à jour de la proposition de parcours
        if (in_array($deliberation->decision, ['autorise_redoublement', 'redoublement', 'ajourne'])) {
            $simulation = $this->planRedoublementService->simulerPlan($etudiant, $deliberation->inscriptionAcademique);

            $nomProgramme = $deliberation->inscriptionAcademique?->programmeRef?->libelle ?? 'Formation LMD';

            // Création ou rafraîchissement du plan de redoublement
            $planGenere = ParcoursPlan::updateOrCreate(
                [
                    'etudiant_id' => $etudiant->id,
                    'inscription_source_id' => $deliberation->inscription_academique_id,
                ],
                [
                    'objectif' => "Plan de réinscription individualisé — {$nomProgramme}",
                    'statut' => 'propose',
                    'version' => 1,
                    'contenu' => $simulation,
                    'auteur_id' => null,
                ]
            );

            // 3. Notification idempotente à l'étudiant
            $this->notificationService->notifierPlanEtabli($etudiant, $planGenere);
        }

        // 4. Inscription dans le journal d'audit immuable
        HistoriqueAcademique::create([
            'etudiant_id' => $etudiant->id,
            'utilisateur_id' => null,
            'action' => 'acquis.synchronisation_deliberation',
            'entite' => 'DecisionDeliberation',
            'entite_id' => $deliberation->id,
            'ancienne_valeur' => null,
            'nouvelle_valeur' => json_encode([
                'decision' => $deliberation->decision,
                'statut_plan' => $planGenere?->statut,
                'credits_capitalises' => $decisionCentrale['credits_capitalises'] ?? 0,
            ], JSON_UNESCAPED_UNICODE),
            'source_systeme' => 'IntegrationAcademiqueHubService',
            'created_at' => now(),
        ]);

        return [
            'succes' => true,
            'etudiant_id' => $etudiant->id,
            'decision_jury' => $deliberation->decision,
            'credits_capitalises' => $decisionCentrale['credits_capitalises'] ?? 0,
            'plan_cree' => $planGenere !== null,
            'plan_id' => $planGenere?->id,
        ];
    }

    /**
     * Traite et synchronise les acquis après une rectification auditée de note (avec PV).
     *
     * @return array<string, mixed>
     */
    public function synchroniserApresCorrectionNote(Note $note, User $auteur): array
    {
        $note->loadMissing('etudiant');
        $etudiant = $note->etudiant;

        if ($etudiant === null) {
            return ['succes' => false, 'message' => 'Étudiant lié à la note introuvable.'];
        }

        // Réévaluation complète de la décision académique
        $nouvelleDecision = $this->moteurDecision->determinerDecision($etudiant);

        // Si un plan existait en attente, le rafraîchir
        $planExistant = ParcoursPlan::where('etudiant_id', $etudiant->id)
            ->whereIn('statut', ['propose', 'brouillon'])
            ->latest('id')
            ->first();

        if ($planExistant !== null) {
            $simulation = $this->planRedoublementService->simulerPlan($etudiant);
            $planExistant->update([
                'contenu' => $simulation,
            ]);
        }

        // Piste d'audit
        HistoriqueAcademique::create([
            'etudiant_id' => $etudiant->id,
            'utilisateur_id' => $auteur->id,
            'action' => 'acquis.recalcul_suite_note',
            'entite' => 'Note',
            'entite_id' => $note->id,
            'ancienne_valeur' => null,
            'nouvelle_valeur' => json_encode([
                'note_id' => $note->id,
                'nouvelle_valeur' => $note->note ?? $note->valeur,
                'credits_apres' => $nouvelleDecision['credits_capitalises'] ?? 0,
            ], JSON_UNESCAPED_UNICODE),
            'source_systeme' => 'IntegrationAcademiqueHubService',
            'created_at' => now(),
        ]);

        return [
            'succes' => true,
            'etudiant_id' => $etudiant->id,
            'credits_capitalises' => $nouvelleDecision['credits_capitalises'] ?? 0,
            'plan_mis_a_jour' => $planExistant !== null,
        ];
    }

    /**
     * Fournit la synthèse globale d'acquis d'un étudiant pour consultation ou API.
     *
     * @return array<string, mixed>
     */
    public function obtenirSyntheseAcquis(Etudiant $etudiant, ?int $anneeUniversitaireId = null): array
    {
        return $this->moteurDecision->determinerDecision($etudiant, $anneeUniversitaireId);
    }
}
