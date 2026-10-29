<?php

namespace App\Modules\SimulationTemporelle\Support;

/**
 * Explicateur de Scores, Contraintes et Arbitrages (Lot 3).
 *
 * Fournit une décomposition transparente et auditable de chaque résultat de simulation :
 * - Scores bruts et pondérés par objectif ;
 * - Détection et motifs détaillés des pénalités ;
 * - Contraintes dures satisfaites vs violées avec valeurs constatées vs seuils ;
 * - Justification explicite du classement et règle de départage (score décroissant, index croissant).
 */
final class ExplicateurScenarios
{
    /**
     * Génère l'explication complète d'une variante évaluée.
     *
     * @param  array{index: int, delta: float, mesures: array<string, mixed>, evaluation: array<string, mixed>}  $variante
     * @param  array<int, array{type: string, valeur: float}>  $contraintes
     * @param  array<int, array{type: string, cible?: float, poids: float}>  $objectifs
     * @return array<string, mixed>
     */
    public function expliquer(array $variante, array $contraintes, array $objectifs): array
    {
        $evaluation = $variante['evaluation'];
        $mesures = $variante['mesures'];
        $totalFinal = (float) ($mesures['total_final'] ?? 0);

        // Analyse détaillée des contraintes dures
        $detailContraintes = [];
        foreach ($contraintes as $c) {
            $type = $c['type'];
            $valeur = (float) $c['valeur'];
            $satisfaite = match ($type) {
                'plafond_total' => $totalFinal <= $valeur,
                'plancher_total' => $totalFinal >= $valeur,
                default => false,
            };

            $detailContraintes[] = [
                'type' => $type,
                'seuil' => $valeur,
                'constate' => $totalFinal,
                'satisfaite' => $satisfaite,
                'motif' => $satisfaite
                    ? "Conforme au seuil réglementaire ({$totalFinal} vs {$valeur})."
                    : "Non respect de la contrainte : total final {$totalFinal} hors borne fixée à {$valeur}.",
            ];
        }

        // Analyse détaillée des objectifs
        $detailObjectifs = [];
        foreach ($objectifs as $obj) {
            $type = $obj['type'];
            $poids = (float) $obj['poids'];
            $scoreBrut = 0.0;
            $explication = '';

            if ($type === 'cible_total') {
                $cible = (float) ($obj['cible'] ?? 0);
                $ecartAbsolu = abs($totalFinal - $cible);
                $ecartPct = $cible > 0 ? round(($ecartAbsolu / $cible) * 100, 2) : 0;
                $scoreBrut = $cible > 0 ? max(0.0, 1.0 - ($ecartAbsolu / $cible)) : 0.0;
                $explication = "Cible de {$cible} étudiants : écart constaté de {$ecartAbsolu} ({$ecartPct}%).";
            } elseif ($type === 'stabilite') {
                $variations = $mesures['variations_annuelles'] ?? [];
                $maxVar = $variations !== [] ? max(array_map('abs', $variations)) : 0;
                $scoreBrut = max(0.0, 1.0 - $maxVar);
                $explication = sprintf('Variation annuelle maximale de %.2f%%.', $maxVar * 100);
            }

            $detailObjectifs[] = [
                'type' => $type,
                'poids' => $poids,
                'score_normalise' => round($scoreBrut, 4),
                'contribution' => round($scoreBrut * $poids, 4),
                'explication' => $explication,
            ];
        }

        return [
            'variante_index' => $variante['index'],
            'delta_admissions' => $variante['delta'],
            'est_conforme' => $evaluation['conforme'] ?? false,
            'score_global' => $evaluation['score'] ?? 0.0,
            'statut_decisionnel' => ($evaluation['conforme'] ?? false) ? 'Éligible au classement' : 'Rejeté pour non-conformité',
            'contraintes' => $detailContraintes,
            'objectifs' => $detailObjectifs,
            'regle_departage' => 'En cas d\'égalité de score, priorité donnée à la variante de référence la plus proche du delta initial (index croissant).',
        ];
    }
}
