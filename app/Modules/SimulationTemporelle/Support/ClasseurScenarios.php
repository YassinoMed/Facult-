<?php

namespace App\Modules\SimulationTemporelle\Support;

/**
 * Classeur DÉTERMINISTE (ST-01) : variantes conformes triées par score
 * décroissant (égalité résolue par l'index croissant — jamais par un
 * ordre d'insertion) ; les non conformes sont listées À PART avec leurs
 * violations (jamais classées comme conformes).
 */
final class ClasseurScenarios
{
    /**
     * @param  array<int, array{index: int, delta: float, evaluation: array}>  $variantesEvaluees
     * @return array{classement: array<int, array>, non_conformes: array<int, array>}
     */
    public function classer(array $variantesEvaluees): array
    {
        $conformes = array_values(array_filter(
            $variantesEvaluees,
            fn ($v) => $v['evaluation']['conforme'] === true
        ));

        usort($conformes, function ($a, $b) {
            return $b['evaluation']['score'] <=> $a['evaluation']['score']
                ?: $a['index'] <=> $b['index'];
        });

        $nonConformes = array_values(array_filter(
            $variantesEvaluees,
            fn ($v) => $v['evaluation']['conforme'] !== true
        ));
        usort($nonConformes, fn ($a, $b) => $a['index'] <=> $b['index']);

        return ['classement' => $conformes, 'non_conformes' => $nonConformes];
    }
}
