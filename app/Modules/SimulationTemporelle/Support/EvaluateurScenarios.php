<?php

namespace App\Modules\SimulationTemporelle\Support;

/**
 * Évaluateur DECLARATIF (ST-01) : contraintes DURES (violées = variante
 * non conforme, jamais classée) et objectifs SOUPLES (scores 0..1,
 * poids SAISIS). Descripteurs JSON-sérialisables — aucune closure en
 * base ; le registre de types ci-dessous est versionné avec le moteur.
 */
final class EvaluateurScenarios
{
    public const CONTRAINTES = ['plafond_total', 'plancher_total'];

    public const OBJECTIFS = ['cible_total', 'stabilite'];

    /**
     * @param  array<int, array{type: string, valeur: float}>  $contraintes
     * @param  array<int, array{type: string, cible?: float, poids: float}>  $objectifs
     * @return array{conforme: bool, violations: array<int, string>, score: float, contributions: array<string, float>}
     */
    public function evaluer(array $mesures, array $contraintes, array $objectifs): array
    {
        $violations = [];

        foreach ($contraintes as $contrainte) {
            $message = $this->verifierContrainte($contrainte, $mesures);
            if ($message !== null) {
                $violations[] = $message;
            }
        }

        $scoreTotal = 0.0;
        $sommePoids = 0.0;
        $contributions = [];

        foreach ($objectifs as $objectif) {
            $score = $this->scoreObjectif($objectif, $mesures);
            $poids = (float) $objectif['poids'];
            $contributions[$objectif['type']] = round($score * $poids, 4);
            $scoreTotal += $score * $poids;
            $sommePoids += $poids;
        }

        return [
            'conforme' => $violations === [],
            'violations' => $violations,
            'score' => $sommePoids > 0 ? round($scoreTotal / $sommePoids, 4) : 0.0,
            'contributions' => $contributions,
        ];
    }

    /** @return string|null message de violation, null si satisfaite */
    private function verifierContrainte(array $contrainte, array $mesures): ?string
    {
        $total = (float) ($mesures['total_final'] ?? 0);

        return match ($contrainte['type']) {
            'plafond_total' => $total <= (float) $contrainte['valeur'] ? null
                : sprintf('Total final %.0f > plafond %.0f.', $total, $contrainte['valeur']),
            'plancher_total' => $total >= (float) $contrainte['valeur'] ? null
                : sprintf('Total final %.0f < plancher %.0f.', $total, $contrainte['valeur']),
            default => 'Type de contrainte inconnu : '.$contrainte['type'].'.',
        };
    }

    private function scoreObjectif(array $objectif, array $mesures): float
    {
        if ($objectif['type'] === 'cible_total') {
            $total = (float) ($mesures['total_final'] ?? 0);
            $cible = (float) $objectif['cible'];

            if ($cible <= 0) {
                return 0.0;
            }

            $ecart = abs($total - $cible) / $cible;

            return round(max(0.0, 1.0 - $ecart), 4);
        }

        if ($objectif['type'] === 'stabilite') {
            $variations = $mesures['variations_annuelles'] ?? [];
            if ($variations === []) {
                return 0.0;
            }

            $maxVariation = max(array_map('abs', $variations));

            return round(max(0.0, 1.0 - $maxVariation), 4);
        }

        return 0.0;
    }
}
