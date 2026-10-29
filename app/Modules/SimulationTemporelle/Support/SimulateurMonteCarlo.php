<?php

namespace App\Modules\SimulationTemporelle\Support;

/**
 * Simulateur Stochastique Déterministe Monte-Carlo (Lot 10).
 *
 * Exécute N itérations déterministes basées sur une graine (SHA256),
 * calcule les percentiles (P10, P50, P90), la moyenne, l'écart-type
 * et la fréquence empirique de violation des contraintes.
 */
final class SimulateurMonteCarlo
{
    public const MAX_ITERATIONS = 200;

    /**
     * Lance la simulation Monte-Carlo.
     *
     * @param  array<string, int>  $cohortesInitiales
     * @param  array<string, mixed>  $criteresBase
     * @param  callable  $simulateurFn  (cohortes, delta, horizon, taux) => total_final
     * @return array{
     *   nb_iterations: int,
     *   graine: string,
     *   moyenne: float,
     *   ecart_type: float,
     *   mediane: float,
     *   p10: float,
     *   p90: float,
     *   min: float,
     *   max: float,
     *   frequence_depassement_plafond_pct: float,
     *   intervalles_simulation: string
     * }
     */
    public function simuler(
        array $cohortesInitiales,
        array $criteresBase,
        int $nbIterations,
        string $graine,
        callable $simulateurFn,
        ?float $plafondContrainte = null
    ): array {
        $iterations = min(max(10, $nbIterations), self::MAX_ITERATIONS);
        $deltaBase = (float) ($criteresBase['delta_admissions'] ?? 0.0);
        $horizon = (int) ($criteresBase['horizon_annees'] ?? 3);
        // RÈGLE : aucun taux inventé. Taux absents = progression gelée
        // (cohérent avec le moteur principal) — signalé explicitement.
        $tauxBase = $criteresBase['taux'] ?? null;

        $totauxFinaux = [];
        $nbDepassements = 0;

        for ($iter = 0; $iter < $iterations; $iter++) {
            // Tirage pseudo-aléatoire déterministe via hash
            $alea1 = GenerateurVariantes::pseudoAleatoire($graine.':mc_admissions', $iter); // [-1, 1]

            // Variation admissions : ± 8%
            $deltaTire = round($deltaBase + ($alea1 * 0.08), 4);

            if ($tauxBase === null) {
                // Taux absents : gel strict, aucune hypothèse substituée.
                $tauxTire = null;
            } else {
                // Variation taux de passage : ± 4%
                $alea2 = GenerateurVariantes::pseudoAleatoire($graine.':mc_taux', $iter); // [-1, 1]
                $passageTire = max(0.0, min(1.0, (float) $tauxBase['passage'] + ($alea2 * 0.04)));
                $tauxTire = array_merge($tauxBase, ['passage' => $passageTire]);
            }

            $total = (float) $simulateurFn($cohortesInitiales, $deltaTire, $horizon, $tauxTire);
            $totauxFinaux[] = $total;

            if ($plafondContrainte !== null && $total > $plafondContrainte) {
                $nbDepassements++;
            }
        }

        sort($totauxFinaux);
        $count = count($totauxFinaux);

        $moyenne = round(array_sum($totauxFinaux) / $count, 1);
        $variance = 0.0;
        foreach ($totauxFinaux as $t) {
            $variance += pow($t - $moyenne, 2);
        }
        $ecartType = round(sqrt($variance / $count), 1);

        $p10Index = (int) floor($count * 0.10);
        $p50Index = (int) floor($count * 0.50);
        $p90Index = (int) min($count - 1, (int) floor($count * 0.90));

        $p10 = $totauxFinaux[$p10Index];
        $mediane = $totauxFinaux[$p50Index];
        $p90 = $totauxFinaux[$p90Index];
        $min = $totauxFinaux[0];
        $max = end($totauxFinaux);

        $freqDepassement = $plafondContrainte !== null
            ? round(($nbDepassements / $count) * 100, 1)
            : 0.0;

        return [
            'nb_iterations' => $count,
            'graine' => $graine,
            'taux_absents' => $tauxBase === null,
            'moyenne' => $moyenne,
            'ecart_type' => $ecartType,
            'mediane' => (float) $mediane,
            'p10' => (float) $p10,
            'p90' => (float) $p90,
            'min' => (float) $min,
            'max' => (float) $max,
            'frequence_depassement_plafond_pct' => $freqDepassement,
            'intervalles_simulation' => "Intervalle P10-P90 simulé : [{$p10}, {$p90}] étudiants (médiane {$mediane}).",
            'avertissement' => $tauxBase === null
                ? 'Aucun taux de progression saisi : les itérations Monte-Carlo projettent des cohortes gelées (aucun taux substitué).'
                : null,
        ];
    }
}
