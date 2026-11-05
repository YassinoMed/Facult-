<?php

namespace App\Services\Acquis;

use App\Models\Etudiant;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Modules\ParcoursAcademique\Models\ParcoursPlan;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * WORKFLOW DE VALIDATION ADMINISTRATIVE ET APPROBATION (LOOP 22).
 *
 * Exécute le cycle strict : SIMULATION → CONTRÔLE → VALIDATION → INSCRIPTION.
 * Applique le principe des 4 yeux (double approbation) pour les opérations sensibles
 * et interdit formellement toute auto-approbation par le demandeur.
 */
class ValidationApprobationPlanService
{
    public function __construct(
        private readonly PlanRedoublementService $planRedoublementService,
        private readonly ReinscriptionPedagogiqueRedoublantService $reinscriptionService,
        private readonly GestionStatutsAcademiquesService $statutsService,
        private readonly ControlePrerequisService $prerequisService,
    ) {}

    /**
     * Contrôle préalable d'un plan prévisionnel avant soumission à validation.
     *
     * LOOP 44 × LOOP 22 (intégration) : les incertitudes RÉGLEMENTAIRES
     * (`BLOQUE_REGLEMENTATION_INCONNUE`, `REVUE_HUMAINE_REQUISE`) ne bloquent
     * plus la validation — la doctrine Gate G40 impose qu'aucune décision
     * défavorable automatique ne soit prise et que le dossier soit transmis
     * à une revue humaine habilitée ; la validation administrative par
     * l'acteur autorisé (avec motif historisé) EST cette revue. Ces points
     * restent exposés via `avertissements_revue_humaine` (jamais masqués).
     * Toute autre anomalie demeure bloquante.
     *
     * @return array{
     *     conforme: bool,
     *     exige_double_approbation: bool,
     *     motifs_double_approbation: array,
     *     erreurs_bloquantes: array,
     *     avertissements_revue_humaine: array
     * }
     */
    public function controlerPlan(Etudiant $etudiant, ?InscriptionAcademique $inscription = null): array
    {
        $simulation = $this->planRedoublementService->simulerPlan($etudiant, $inscription);

        $erreursBloquantes = [];
        $motifsDoubleApprobation = [];
        $avertissementsRevue = [];

        // 1. Contrôle de publication de la délibération
        if (! $simulation['deliberation_publiee']) {
            $erreursBloquantes[] = 'Délibération non publiée : les résultats ne sont pas définitifs.';
        }

        // 2. Incertitudes réglementaires (revue humaine, Gate G40) et
        //    anomalies bloquantes. Une incertitude de règle n'invalide pas
        //    le dossier : elle exige une revue humaine — la validation
        //    administrative par l'acteur autorisé (avec motif historisé)
        //    EST cette revue. Toute autre anomalie demeure bloquante.
        foreach ($simulation['revue_humaine'] ?? [] as $revue) {
            $avertissementsRevue[] = "Revue humaine requise (règle non confirmée) : {$revue}";
        }

        if (! empty($simulation['anomalies'])) {
            foreach ($simulation['anomalies'] as $ano) {
                $erreursBloquantes[] = "Anomalie non résolue : {$ano}";
            }
        }

        // 3. Contrôle des prérequis bloquants
        if (! empty($simulation['prerequis_bloquants'])) {
            foreach ($simulation['prerequis_bloquants'] as $pb) {
                $erreursBloquantes[] = "Prérequis non satisfait : Matière #{$pb['ecue_id']} (prérequis #{$pb['prerequis_id']}).";
            }
        }

        // 4. Détection des cas sensibles exigeant la double approbation (4 yeux)
        // A) Présence de dispenses ou équivalences
        $dispenseOuEquivalence = collect($simulation['elements_conserves'])->contains(function ($el) {
            return in_array($el['statut'] ?? '', ['DISPENSE_OFFICIELLE', 'EQUIVALENCE_VALIDEE'], true);
        });
        if ($dispenseOuEquivalence) {
            $motifsDoubleApprobation[] = 'Présence de dispenses ou équivalences extérieures dans les acquis conservés.';
        }

        // B) Changement de parcours / réorientation
        $derniereInscription = InscriptionAcademique::where('etudiant_id', $etudiant->id)->latest('id')->first();
        if ($derniereInscription !== null && $derniereInscription->programme_id !== null && $etudiant->programme_id !== null) {
            if ((int) $derniereInscription->programme_id !== (int) $etudiant->programme_id) {
                $motifsDoubleApprobation[] = 'Changement de programme/parcours constaté entre deux inscriptions.';
            }
        }

        $exigeDoubleApprobation = ! empty($motifsDoubleApprobation);
        $conforme = empty($erreursBloquantes);

        return [
            'conforme' => $conforme,
            'exige_double_approbation' => $exigeDoubleApprobation,
            'motifs_double_approbation' => $motifsDoubleApprobation,
            'erreurs_bloquantes' => $erreursBloquantes,
            'avertissements_revue_humaine' => $avertissementsRevue,
        ];
    }

    /**
     * Valide et approuve administrativement le plan de redoublement.
     * En cas d'opération sensible, exige un second signataire distinct de l'initiateur (règle des 4 yeux).
     */
    public function validerPlan(
        ParcoursPlan $plan,
        int $approbateurId,
        ?string $motif = null,
        bool $estSecondApprobateur = false
    ): ParcoursPlan {
        $controle = $this->controlerPlan($plan->etudiant, $plan->inscriptionSource);

        if (! $controle['conforme']) {
            $raisons = implode(' | ', $controle['erreurs_bloquantes']);
            throw new DomainException("Impossible de valider le plan : contrôles non conformes [{$raisons}].");
        }

        $auteurId = (int) $plan->auteur_id;

        // PRINCIPE DES 4 YEUX : Interdiction d'auto-approbation
        if ($controle['exige_double_approbation']) {
            if ($auteurId > 0 && $auteurId === $approbateurId) {
                throw new DomainException(
                    'Auto-approbation strictement interdite : le plan comporte des clauses sensibles (4 yeux requis) et ne peut être approuvé par son propre initiateur.'
                );
            }
        }

        return DB::transaction(function () use ($plan, $approbateurId, $motif, $controle, $estSecondApprobateur) {
            $contenu = is_array($plan->contenu) ? $plan->contenu : json_decode((string) $plan->contenu, true);
            $approbations = $contenu['approbations'] ?? [];

            $approbations[] = [
                'acteur_id' => $approbateurId,
                'role' => $estSecondApprobateur ? 'approbateur_secondaire' : 'approbateur_principal',
                'date' => now()->toIso8601String(),
                'motif' => $motif ?? 'Validation administrative conforme aux règlements',
            ];

            $contenu['approbations'] = $approbations;

            // Détermination du statut : si 4 yeux requis et pas encore 2 approbateurs distincts -> EN_VALIDATION
            $nbApprobateursDistincts = count(array_unique(array_column($approbations, 'acteur_id')));

            if ($controle['exige_double_approbation'] && $nbApprobateursDistincts < 2) {
                $nouveauStatut = GestionStatutsAcademiquesService::DOSSIER_EN_VALIDATION;
            } else {
                $nouveauStatut = GestionStatutsAcademiquesService::DOSSIER_VALIDE;
            }

            $plan->update([
                'contenu' => $contenu,
                'statut' => strtolower($nouveauStatut),
            ]);

            HistoriqueAcademique::create([
                'etudiant_id' => $plan->etudiant_id,
                'utilisateur_id' => $approbateurId,
                'action' => 'approbation_plan_redoublement',
                'entite' => 'parcours_plans',
                'entite_id' => $plan->id,
                'ancienne_valeur' => $plan->statut,
                'nouvelle_valeur' => json_encode([
                    'statut' => $nouveauStatut,
                    'approbateur_id' => $approbateurId,
                    'nb_approbateurs' => $nbApprobateursDistincts,
                    'motif' => $motif,
                ], JSON_UNESCAPED_UNICODE),
                'source_systeme' => 'workflow_redoublement',
            ]);

            return $plan;
        });
    }

    /**
     * Étape finale : Création de l'inscription pédagogique sélective à partir du plan validé.
     *
     * @return array<string, mixed>
     */
    public function activerPlanEtCreerInscription(
        ParcoursPlan $plan,
        InscriptionAcademique $nouvelleInscription,
        int $agentId
    ): array {
        if (strtolower((string) $plan->statut) !== strtolower(GestionStatutsAcademiquesService::DOSSIER_VALIDE)) {
            throw new DomainException(
                "Activation impossible : le plan doit être au statut [VALIDE] avant de générer l'inscription pédagogique (statut actuel: {$plan->statut})."
            );
        }

        $resultat = $this->reinscriptionService->reinscrireRedoublant(
            $plan->etudiant,
            $nouvelleInscription,
            $agentId
        );

        $plan->update([
            'statut' => strtolower(GestionStatutsAcademiquesService::DOSSIER_INSCRIPTION_CREEE),
        ]);

        return $resultat;
    }
}
