<?php

namespace App\Modules\SimulationTemporelle\Support;

/**
 * Projecteur Budgétaire Hypothétique d'Aide à la Décision (Lot 11).
 *
 * RÈGLES DU MANDAT :
 * - Modélisation purement déclarative et prospective ;
 * - N'écrit JAMAIS dans les tables de finances, paiements, factures ou comptabilité réelles ;
 * - Calcule les coûts fixes, variables (par étudiant, par groupe) et recettes simulées.
 */
final class ProjecteurBudgetaire
{
    public const COUT_FIXE_DEFAUT = 50000.0; // En devise locale (DT / MAD / EUR selon config)

    public const COUT_VARIABLE_ETUDIANT_DEFAUT = 350.0;

    public const COUT_GROUPE_DEFAUT = 4500.0;

    public const RECETTE_PAR_ETUDIANT_DEFAUT = 120.0;

    /**
     * Calcule la projection budgétaire sur la trajectoire d'effectifs.
     *
     * @param  array<int, int>  $totauxParAnnee
     * @param  array<string, float>|null  $parametresBudget
     * @return array<string, mixed>
     */
    public function projeter(array $totauxParAnnee, int $groupesRequisFinal, ?array $parametresBudget = null): array
    {
        $coutFixe = (float) ($parametresBudget['cout_fixe_annuel'] ?? self::COUT_FIXE_DEFAUT);
        $coutParEtudiant = (float) ($parametresBudget['cout_par_etudiant'] ?? self::COUT_VARIABLE_ETUDIANT_DEFAUT);
        $coutParGroupe = (float) ($parametresBudget['cout_par_groupe'] ?? self::COUT_GROUPE_DEFAUT);
        $recetteParEtudiant = (float) ($parametresBudget['recette_par_etudiant'] ?? self::RECETTE_PAR_ETUDIANT_DEFAUT);

        $initial = reset($totauxParAnnee) ?: 0;
        $final = end($totauxParAnnee) ?: 0;

        $coutVariablesInit = $initial * $coutParEtudiant;
        $coutTotalInit = $coutFixe + $coutVariablesInit;
        $recettesInit = $initial * $recetteParEtudiant;
        $soldeInit = $recettesInit - $coutTotalInit;

        $coutVariablesFinal = $final * $coutParEtudiant;
        $coutGroupesFinal = $groupesRequisFinal * $coutParGroupe;
        $coutTotalFinal = $coutFixe + $coutVariablesFinal + $coutGroupesFinal;
        $recettesFinal = $final * $recetteParEtudiant;
        $soldeFinal = $recettesFinal - $coutTotalFinal;

        return [
            'hypothese_cout_fixe' => $coutFixe,
            'hypothese_cout_variable_etudiant' => $coutParEtudiant,
            'hypothese_cout_groupe' => $coutParGroupe,
            'hypothese_recette_etudiant' => $recetteParEtudiant,
            // Traçabilité : false = paramètres SAISIS ; true = défauts du
            // moteur (hypothèse fictive, jamais un budget réel mesuré).
            'parametres_configures' => $parametresBudget !== null,
            'cout_total_initial' => round($coutTotalInit, 2),
            'cout_total_projete' => round($coutTotalFinal, 2),
            'ecart_cout_absolu' => round($coutTotalFinal - $coutTotalInit, 2),
            'recettes_initiales' => round($recettesInit, 2),
            'recettes_projetees' => round($recettesFinal, 2),
            'solde_initial' => round($soldeInit, 2),
            'solde_projete' => round($soldeFinal, 2),
            'variation_budget_pct' => $coutTotalInit > 0 ? round((($coutTotalFinal - $coutTotalInit) / $coutTotalInit) * 100, 2) : 0,
            'avertissement' => 'Projection financière purement prospective — aucun impact réel sur les comptes de scolarité ni le registre financier.',
        ];
    }
}
