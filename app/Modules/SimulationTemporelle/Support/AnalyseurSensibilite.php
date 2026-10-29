<?php

namespace App\Modules\SimulationTemporelle\Support;

/**
 * Analyseur Déterministe de Sensibilité Univariée (OAT - Lot 7).
 *
 * Mesure l'influence individuelle de chaque hypothèse (delta admissions,
 * taux de passage, taux de redoublement, horizon) sur les effectifs finaux
 * et classe les paramètres par ordre de sensibilité relative.
 */
final class AnalyseurSensibilite
{
    /**
     * Analyse la sensibilité des résultats autour des paramètres de base.
     *
     * @param  array<string, int>  $cohortesInitiales
     * @param  array<string, mixed>  $criteresBase
     * @param  callable  $simulateurFn  Fonction (cohortes, delta, horizon, taux) => total_final
     * @return array{
     *   parametres_classes: array<int, array<string, mixed>>,
     *   synthese: string
     * }
     */
    public function analyser(array $cohortesInitiales, array $criteresBase, callable $simulateurFn): array
    {
        $deltaBase = (float) ($criteresBase['delta_admissions'] ?? 0.0);
        $horizonBase = (int) ($criteresBase['horizon_annees'] ?? 3);
        // RÈGLE : aucun taux inventé. Taux absents = progression gelée ;
        // la sensibilité des taux est alors signalée non mesurable.
        $tauxBase = $criteresBase['taux'] ?? null;

        $totalBase = (float) $simulateurFn($cohortesInitiales, $deltaBase, $horizonBase, $tauxBase);

        $resultats = [];

        // 1. Sensibilité au delta d'admissions (+/- 10%)
        $deltaHaut = $deltaBase + 0.10;
        $deltaBas = $deltaBase - 0.10;
        $totalDeltaHaut = (float) $simulateurFn($cohortesInitiales, $deltaHaut, $horizonBase, $tauxBase);
        $totalDeltaBas = (float) $simulateurFn($cohortesInitiales, $deltaBas, $horizonBase, $tauxBase);
        $ecartAdmissions = abs($totalDeltaHaut - $totalDeltaBas);
        $elasticiteAdmissions = $totalBase > 0 ? round(($ecartAdmissions / $totalBase) * 100, 2) : 0;

        $resultats[] = [
            'parametre' => 'delta_admissions',
            'libelle' => 'Taux d\'admission en L1',
            'valeur_base' => $deltaBase,
            'variation_testee' => '±10%',
            'impact_absolu_eleves' => (int) round($ecartAdmissions),
            'sensibilite_pct' => $elasticiteAdmissions,
            'interpretation' => "Une variation de 10 points sur les admissions modifie le total final de {$elasticiteAdmissions}%.",
        ];

        // 2. Sensibilité au taux de passage (+/- 5%) — uniquement si saisis
        if ($tauxBase === null) {
            foreach (['passage' => 'Taux de réussite / passage', 'redoublement' => 'Taux de redoublement'] as $taux => $libelle) {
                $resultats[] = [
                    'parametre' => 'taux_'.$taux,
                    'libelle' => $libelle,
                    'valeur_base' => null,
                    'variation_testee' => 'N/A',
                    'impact_absolu_eleves' => 0,
                    'sensibilite_pct' => 0.0,
                    'interpretation' => 'Taux non saisi : influence non mesurable (progression gelée). Saisir le taux pour activer l\'analyse — aucune valeur substituée.',
                ];
            }
        } else {
            $tauxPassageHaut = array_merge($tauxBase, ['passage' => min(1.0, (float) $tauxBase['passage'] + 0.05)]);
            $tauxPassageBas = array_merge($tauxBase, ['passage' => max(0.0, (float) $tauxBase['passage'] - 0.05)]);
            $totalPassageHaut = (float) $simulateurFn($cohortesInitiales, $deltaBase, $horizonBase, $tauxPassageHaut);
            $totalPassageBas = (float) $simulateurFn($cohortesInitiales, $deltaBase, $horizonBase, $tauxPassageBas);
            $ecartPassage = abs($totalPassageHaut - $totalPassageBas);
            $elasticitePassage = $totalBase > 0 ? round(($ecartPassage / $totalBase) * 100, 2) : 0;

            $resultats[] = [
                'parametre' => 'taux_passage',
                'libelle' => 'Taux de réussite / passage',
                'valeur_base' => (float) $tauxBase['passage'],
                'variation_testee' => '±5%',
                'impact_absolu_eleves' => (int) round($ecartPassage),
                'sensibilite_pct' => $elasticitePassage,
                'interpretation' => "Une variation de 5 points sur le passage modifie le total final de {$elasticitePassage}%.",
            ];

            // 3. Sensibilité au taux de redoublement (+/- 5%)
            $tauxRedoubleHaut = array_merge($tauxBase, ['redoublement' => min(1.0, (float) $tauxBase['redoublement'] + 0.05)]);
            $tauxRedoubleBas = array_merge($tauxBase, ['redoublement' => max(0.0, (float) $tauxBase['redoublement'] - 0.05)]);
            $totalRedoubleHaut = (float) $simulateurFn($cohortesInitiales, $deltaBase, $horizonBase, $tauxRedoubleHaut);
            $totalRedoubleBas = (float) $simulateurFn($cohortesInitiales, $deltaBase, $horizonBase, $tauxRedoubleBas);
            $ecartRedouble = abs($totalRedoubleHaut - $totalRedoubleBas);
            $elasticiteRedouble = $totalBase > 0 ? round(($ecartRedouble / $totalBase) * 100, 2) : 0;

            $resultats[] = [
                'parametre' => 'taux_redoublement',
                'libelle' => 'Taux de redoublement',
                'valeur_base' => (float) $tauxBase['redoublement'],
                'variation_testee' => '±5%',
                'impact_absolu_eleves' => (int) round($ecartRedouble),
                'sensibilite_pct' => $elasticiteRedouble,
                'interpretation' => "Une variation de 5 points sur le redoublement modifie le total final de {$elasticiteRedouble}%.",
            ];
        }

        // Tri par sensibilité décroissante
        usort($resultats, fn ($a, $b) => $b['sensibilite_pct'] <=> $a['sensibilite_pct']);

        $top = $resultats[0]['libelle'] ?? 'paramètre principal';
        $synthese = "Le paramètre le plus influent sur l'horizon de {$horizonBase} ans est : {$top}.";

        return [
            'parametres_classes' => $resultats,
            'synthese' => $synthese,
        ];
    }
}
