<?php

namespace App\Modules\SimulationTemporelle\Support;

/**
 * Gestionnaire d'Alertes de Seuil Déclaratives pour la Simulation (Lot 13).
 *
 * Évalue les indicateurs simulés par rapport aux seuils de vigilance configurés
 * et consigne les alertes qualifiées sans modifier aucune donnée métier réelle.
 */
final class GestionnaireAlertesSeuils
{
    public const GRAVITE_CRITIQUE = 'critique';

    public const GRAVITE_ALERTE = 'alerte';

    public const GRAVITE_INFO = 'info';

    /**
     * Évalue un jeu de seuils sur les résultats de simulation.
     *
     * @param  array<string, mixed>  $mesuresVariante
     * @param  array<string, mixed>|null  $capacites
     * @param  array<string, mixed>|null  $budget
     * @param  array<int, array{type: string, seuil: float, gravite?: string}>  $seuilsConfigures
     * @return array<int, array{code: string, gravite: string, indicateur: string, seuil: float, valeur_constatee: float, message: string}>
     */
    public function evaluer(
        array $mesuresVariante,
        ?array $capacites = null,
        ?array $budget = null,
        array $seuilsConfigures = []
    ): array {
        $alertes = [];
        $totalFinal = (float) ($mesuresVariante['total_final'] ?? 0);
        $variations = $mesuresVariante['variations_annuelles'] ?? [];
        $maxVariation = $variations !== [] ? max(array_map('abs', $variations)) * 100 : 0.0;

        // Configuration par défaut si non spécifiée
        if ($seuilsConfigures === []) {
            $seuilsConfigures = [
                ['type' => 'variation_annuelle_max', 'seuil' => 15.0, 'gravite' => self::GRAVITE_ALERTE],
                ['type' => 'saturation_capacite_pct', 'seuil' => 95.0, 'gravite' => self::GRAVITE_CRITIQUE],
            ];
        }

        foreach ($seuilsConfigures as $s) {
            $type = $s['type'];
            $seuil = (float) $s['seuil'];
            $gravite = $s['gravite'] ?? self::GRAVITE_ALERTE;

            if ($type === 'variation_annuelle_max') {
                if ($maxVariation > $seuil) {
                    $alertes[] = [
                        'code' => 'ALERTE_VARIATION_BRUTALE',
                        'gravite' => $gravite,
                        'indicateur' => 'Variation annuelle maximale d\'effectifs',
                        'seuil' => $seuil,
                        'valeur_constatee' => round($maxVariation, 2),
                        'message' => sprintf('Une variation d\'effectif de %.1f%% dépasse le seuil de vigilance de %.1f%%.', $maxVariation, $seuil),
                    ];
                }
            } elseif ($type === 'saturation_capacite_pct') {
                $satPct = (float) ($capacites['taux_saturation_global_pct'] ?? 0.0);
                if ($satPct > $seuil) {
                    $alertes[] = [
                        'code' => 'ALERTE_SATURATION_CAPACITE',
                        'gravite' => $gravite,
                        'indicateur' => 'Taux d\'occupation de la capacité d\'accueil',
                        'seuil' => $seuil,
                        'valeur_constatee' => $satPct,
                        'message' => sprintf('Taux de saturation des groupes de %.1f%% (seuil d\'alerte : %.1f%%).', $satPct, $seuil),
                    ];
                }
            } elseif ($type === 'deficit_budgetaire') {
                $solde = (float) ($budget['solde_projete'] ?? 0.0);
                if ($solde < $seuil) {
                    $alertes[] = [
                        'code' => 'ALERTE_DEFICIT_SIMULE',
                        'gravite' => self::GRAVITE_CRITIQUE,
                        'indicateur' => 'Solde budgétaire simulé',
                        'seuil' => $seuil,
                        'valeur_constatee' => $solde,
                        'message' => sprintf('Le budget simulé présente un solde de %.0f inférieur au seuil de sécurité (%.0f).', $solde, $seuil),
                    ];
                }
            } elseif ($type === 'effectif_plafond') {
                if ($totalFinal > $seuil) {
                    $alertes[] = [
                        'code' => 'ALERTE_DEPASSEMENT_EFFECTIF',
                        'gravite' => $gravite,
                        'indicateur' => 'Effectif final projeté',
                        'seuil' => $seuil,
                        'valeur_constatee' => $totalFinal,
                        'message' => sprintf('L\'effectif final de %.0f dépasse le seuil configuré de %.0f.', $totalFinal, $seuil),
                    ];
                }
            }
        }

        return $alertes;
    }
}
