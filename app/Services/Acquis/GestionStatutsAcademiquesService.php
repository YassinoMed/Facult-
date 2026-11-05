<?php

namespace App\Services\Acquis;

use App\Models\HistoriqueAcademique;
use App\Models\User;
use App\Modules\ParcoursAcademique\Models\ParcoursPlan;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * GESTIONNAIRE DES STATUTS ACADÉMIQUES ET WORKFLOWS DE TRANSITION (LOOP 17).
 *
 * Uniformise les états des acquis et du redoublement, régule strictement
 * les transitions admissibles, interdit les mutations illégales et audite
 * chaque changement d'état.
 */
class GestionStatutsAcademiquesService
{
    // ==========================================
    // STATUTS DES UNITÉS D'ENSEIGNEMENT (UE)
    // ==========================================
    public const UE_CAPITALISEE = 'CAPITALISEE';

    public const UE_VALIDEE_PAR_COMPENSATION = 'VALIDEE_PAR_COMPENSATION';

    public const UE_NON_VALIDEE = 'NON_VALIDEE';

    public const UE_A_REPRENDRE = 'A_REPRENDRE';

    public const UE_EN_ATTENTE_DELIBERATION = 'EN_ATTENTE_DELIBERATION';

    public const UE_SEUIL_NON_ATTEINT = 'SEUIL_COMPETENCE_NON_ATTEINT';

    public const UE_EQUIVALENCE_EN_ATTENTE = 'EQUIVALENCE_EN_ATTENTE';

    public const UE_DISPENSE_EN_ATTENTE = 'DISPENSE_EN_ATTENTE';

    public const UE_ANOMALIE_A_TRAITER = 'ANOMALIE_A_TRAITER';

    // ==========================================
    // STATUTS DES ÉLÉMENTS CONSTITUTIFS (ECUE)
    // ==========================================
    public const ECUE_CAPITALISE = 'CAPITALISE';

    public const ECUE_NON_CAPITALISE = 'NON_CAPITALISE';

    public const ECUE_A_REPRENDRE = 'A_REPRENDRE';

    public const ECUE_EN_ATTENTE_RESULTAT = 'EN_ATTENTE_RESULTAT';

    public const ECUE_SEUIL_NON_ATTEINT = 'SEUIL_NON_ATTEINT';

    public const ECUE_SANS_CREDITS = 'SANS_CREDITS_INDEPENDANTS';

    public const ECUE_DECISION_A_VERIFIER = 'DECISION_A_VERIFIER';

    // ==========================================
    // STATUTS DU DOSSIER / PLAN DE REDOUBLEMENT
    // ==========================================
    public const DOSSIER_BROUILLON = 'BROUILLON';

    public const DOSSIER_ANALYSE_EN_COURS = 'ANALYSE_EN_COURS';

    public const DOSSIER_SIMULATION_TERMINEE = 'SIMULATION_TERMINEE';

    public const DOSSIER_ANOMALIE_DETECTEE = 'ANOMALIE_DETECTEE';

    public const DOSSIER_EN_VALIDATION = 'EN_VALIDATION';

    public const DOSSIER_VALIDE = 'VALIDE';

    public const DOSSIER_INSCRIPTION_CREEE = 'INSCRIPTION_PEDAGOGIQUE_CREEE';

    public const DOSSIER_BLOQUE = 'BLOQUE';

    /**
     * Matrice des transitions permises pour les dossiers de redoublement.
     *
     * @var array<string, array<string>>
     */
    private const TRANSITIONS_DOSSIER = [
        self::DOSSIER_BROUILLON => [
            self::DOSSIER_ANALYSE_EN_COURS,
            self::DOSSIER_BLOQUE,
        ],
        self::DOSSIER_ANALYSE_EN_COURS => [
            self::DOSSIER_SIMULATION_TERMINEE,
            self::DOSSIER_ANOMALIE_DETECTEE,
            self::DOSSIER_BLOQUE,
        ],
        self::DOSSIER_SIMULATION_TERMINEE => [
            self::DOSSIER_EN_VALIDATION,
            self::DOSSIER_ANOMALIE_DETECTEE,
            self::DOSSIER_BLOQUE,
        ],
        self::DOSSIER_ANOMALIE_DETECTEE => [
            self::DOSSIER_ANALYSE_EN_COURS,
            self::DOSSIER_BLOQUE,
        ],
        self::DOSSIER_EN_VALIDATION => [
            self::DOSSIER_VALIDE,
            self::DOSSIER_ANOMALIE_DETECTEE,
            self::DOSSIER_BLOQUE,
            self::DOSSIER_SIMULATION_TERMINEE,
        ],
        self::DOSSIER_VALIDE => [
            self::DOSSIER_INSCRIPTION_CREEE,
            self::DOSSIER_EN_VALIDATION, // Réouverture exceptionnelle auditée
        ],
        self::DOSSIER_INSCRIPTION_CREEE => [],
        self::DOSSIER_BLOQUE => [
            self::DOSSIER_ANALYSE_EN_COURS,
        ],
    ];

    /**
     * Vérifie si une transition d'état d'UE est admissible.
     *
     * @param  array<string, mixed>  $contexte
     * @return array{autorise: bool, motif_rejet: ?string}
     */
    public function validerTransitionUe(string $statutActuel, string $nouveauStatut, array $contexte = []): array
    {
        // 1. Transition interdite : EN_ATTENTE_DELIBERATION -> CAPITALISEE sans résultat définitif publié
        if ($statutActuel === self::UE_EN_ATTENTE_DELIBERATION && $nouveauStatut === self::UE_CAPITALISEE) {
            if (empty($contexte['deliberation_publiee'])) {
                return [
                    'autorise' => false,
                    'motif_rejet' => 'Transition interdite : impossible de capitaliser une UE tant que la délibération officielle n\'est pas publiée.',
                ];
            }
        }

        // 2. Transition interdite : EQUIVALENCE_EN_ATTENTE -> CAPITALISEE sans acte officiel
        if ($statutActuel === self::UE_EQUIVALENCE_EN_ATTENTE && $nouveauStatut === self::UE_CAPITALISEE) {
            if (empty($contexte['equivalence_validee'])) {
                return [
                    'autorise' => false,
                    'motif_rejet' => 'Transition interdite : l\'équivalence n\'a pas fait l\'objet d\'une validation par la commission pédagogique.',
                ];
            }
        }

        // 3. Transition interdite : DISPENSE_EN_ATTENTE -> CAPITALISEE sans acte officiel
        if ($statutActuel === self::UE_DISPENSE_EN_ATTENTE && $nouveauStatut === self::UE_CAPITALISEE) {
            if (empty($contexte['dispense_validee'])) {
                return [
                    'autorise' => false,
                    'motif_rejet' => 'Transition interdite : la dispense n\'a pas fait l\'objet d\'un acte officiel validé.',
                ];
            }
        }

        // 4. Transition interdite : NON_VALIDEE -> CAPITALISEE par simple forçage sans justification
        if ($statutActuel === self::UE_NON_VALIDEE && $nouveauStatut === self::UE_CAPITALISEE) {
            if (empty($contexte['moyenne_validee']) && empty($contexte['decision_jury'])) {
                return [
                    'autorise' => false,
                    'motif_rejet' => 'Transition interdite : une UE non validée ne peut être marquée capitalisée sans moyenne >= 10 ou décision expresse de jury.',
                ];
            }
        }

        // 5. Transition interdite : ANOMALIE_A_TRAITER sans résolution
        if ($statutActuel === self::UE_ANOMALIE_A_TRAITER && in_array($nouveauStatut, [self::UE_CAPITALISEE, self::UE_VALIDEE_PAR_COMPENSATION], true)) {
            if (empty($contexte['anomalie_resolue'])) {
                return [
                    'autorise' => false,
                    'motif_rejet' => 'Transition interdite : l\'anomalie académique identifiée n\'a pas été officiellement résolue.',
                ];
            }
        }

        return ['autorise' => true, 'motif_rejet' => null];
    }

    /**
     * Valide et exécute la transition d'un dossier / plan de redoublement avec traçabilité intégrale.
     *
     * @param  array<string, mixed>  $donneesDeclencheuses
     */
    public function transitionnerDossier(
        ParcoursPlan $plan,
        string $nouveauStatut,
        ?int $acteurId,
        string $motif,
        ?string $referenceDecision = null,
        array $donneesDeclencheuses = []
    ): ParcoursPlan {
        $ancienStatut = strtoupper((string) ($plan->statut ?? self::DOSSIER_BROUILLON));
        $nouveauStatut = strtoupper($nouveauStatut);

        // Vérification de la matrice des transitions
        $transitionsPossibles = self::TRANSITIONS_DOSSIER[$ancienStatut] ?? [];
        if (! in_array($nouveauStatut, $transitionsPossibles, true)) {
            throw new DomainException(
                "Transition de dossier illégale : impossible de passer de [{$ancienStatut}] à [{$nouveauStatut}]."
            );
        }

        // Contrôle de transition vers VALIDE : interdiction si anomalies non résolues
        if ($nouveauStatut === self::DOSSIER_VALIDE) {
            $contenu = is_array($plan->contenu) ? $plan->contenu : json_decode((string) $plan->contenu, true);
            if (! empty($contenu['anomalies'])) {
                throw new DomainException(
                    'Transition interdite : le plan comporte des anomalies académiques non résolues.'
                );
            }
        }

        // Contrôle vers INSCRIPTION_PEDAGOGIQUE_CREEE : interdit depuis BROUILLON
        if ($nouveauStatut === self::DOSSIER_INSCRIPTION_CREEE && $ancienStatut !== self::DOSSIER_VALIDE) {
            throw new DomainException(
                "Transition interdite : impossible de créer l'inscription pédagogique sans validation préalable (statut actuel: {$ancienStatut})."
            );
        }

        return DB::transaction(function () use ($plan, $ancienStatut, $nouveauStatut, $acteurId, $motif, $referenceDecision, $donneesDeclencheuses) {
            $userValide = $acteurId !== null && User::where('id', $acteurId)->exists();
            $acteurFinal = $userValide ? $acteurId : null;

            $plan->update([
                'statut' => strtolower($nouveauStatut),
            ]);

            // Enregistrement d'audit dans HistoriqueAcademique
            HistoriqueAcademique::create([
                'etudiant_id' => $plan->etudiant_id,
                'utilisateur_id' => $acteurFinal,
                'action' => 'transition_statut_redoublement',
                'entite' => 'parcours_plans',
                'entite_id' => $plan->id,
                'ancienne_valeur' => $ancienStatut,
                'nouvelle_valeur' => json_encode([
                    'nouveau_statut' => $nouveauStatut,
                    'motif' => $motif,
                    'reference_decision' => $referenceDecision,
                    'version_regle' => 'Arrêté du 7 juillet 2023 - Art. 10',
                    'donnees' => $donneesDeclencheuses,
                    'horodatage' => now()->toIso8601String(),
                ], JSON_UNESCAPED_UNICODE),
                'source_systeme' => 'workflow_redoublement',
            ]);

            return $plan;
        });
    }
}
