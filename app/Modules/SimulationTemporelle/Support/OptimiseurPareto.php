<?php

namespace App\Modules\SimulationTemporelle\Support;

/**
 * Optimiseur Multiobjectif et Frontière de Pareto Déterministe (Lot 9).
 *
 * Identifie les solutions non dominées parmi l'ensemble des variantes conformes,
 * sans agréger arbitrairement les critères contradictoires en un score unique.
 */
final class OptimiseurPareto
{
    /**
     * Calcule la frontière de Pareto sur les variantes conformes.
     *
     * @param  array<int, array{index: int, delta: float, total_final: int, score: float, contributions: array<string, float>}>  $variantesConformes
     * @return array{
     *   frontiere_pareto: array<int, array<string, mixed>>,
     *   solutions_dominees: array<int, array<string, mixed>>,
     *   compromis: string
     * }
     */
    public function calculerFrontiere(array $variantesConformes): array
    {
        if ($variantesConformes === []) {
            return [
                'frontiere_pareto' => [],
                'solutions_dominees' => [],
                'compromis' => 'Aucune variante conforme éligible à la frontière de Pareto.',
            ];
        }

        $pareto = [];
        $dominees = [];

        // Pour chaque variante, vérifier si elle est dominée par au moins une autre
        foreach ($variantesConformes as $i => $candidat) {
            $estDominee = false;

            foreach ($variantesConformes as $j => $autre) {
                if ($i === $j) {
                    continue;
                }

                // Une solution Autre domine Candidat si :
                // Autre est >= sur tous les critères ET > sur au moins un critère
                $scoreA = (float) $autre['score'];
                $scoreC = (float) $candidat['score'];

                $stabA = (float) ($autre['contributions']['stabilite'] ?? 0);
                $stabC = (float) ($candidat['contributions']['stabilite'] ?? 0);

                if (($scoreA >= $scoreC && $stabA >= $stabC) && ($scoreA > $scoreC || $stabA > $stabC)) {
                    $estDominee = true;
                    break;
                }
            }

            if ($estDominee) {
                $dominees[] = $candidat;
            } else {
                $pareto[] = $candidat;
            }
        }

        // Tri des solutions Pareto par score décroissant
        usort($pareto, fn ($a, $b) => $b['score'] <=> $a['score'] ?: $a['index'] <=> $b['index']);

        $nbPareto = count($pareto);
        $compromis = "{$nbPareto} solution(s) non dominée(s) retenue(s) sur la frontière de Pareto.";

        return [
            'frontiere_pareto' => $pareto,
            'solutions_dominees' => $dominees,
            'compromis' => $compromis,
        ];
    }
}
