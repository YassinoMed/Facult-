<?php

namespace App\Modules\SimulationTemporelle\Support;

/**
 * Comparateur Structuré de Projections Temporelles (Lot 2).
 *
 * Compare rigoureusement l'état initial de référence, la variante sélectionnée
 * et la variante de référence (index 0), année par année, avec flux et écarts.
 */
final class ComparateurProjections
{
    /**
     * Construit le tableau comparatif détaillé.
     *
     * @param  array{cohortes: array<string, int>, annee_source: string, total_initial: int}  $etatInitial
     * @param  array{index: int, delta: float, totaux: array<int, int>, total_final: int}  $varianteRetenue
     * @param  array{index: int, delta: float, totaux: array<int, int>, total_final: int}|null  $varianteReference
     * @param  array<int, array{type: string, valeur: float}>  $contraintes
     * @return array{
     *   lignes: array<int, array<string, mixed>>,
     *   synthese_ecarts: array<string, mixed>
     * }
     */
    public function comparer(
        array $etatInitial,
        array $varianteRetenue,
        ?array $varianteReference = null,
        ?float $cibleMetier = null,
        array $contraintes = []
    ): array {
        $anneeBase = (int) substr((string) ($etatInitial['annee_source'] ?? '2026'), 0, 4);
        $totalInitial = (int) ($etatInitial['total_initial'] ?? array_sum($etatInitial['cohortes'] ?? []));

        $totauxRetenus = $varianteRetenue['totaux'] ?? [$totalInitial];
        $totauxRef = $varianteReference['totaux'] ?? $totauxRetenus;

        $nbPas = max(count($totauxRetenus), count($totauxRef));
        $lignes = [];

        for ($pas = 0; $pas < $nbPas; $pas++) {
            $anneeLibelle = ($anneeBase + $pas).'-'.($anneeBase + $pas + 1);
            $valRetenue = $totauxRetenus[$pas] ?? end($totauxRetenus);
            $valRef = $totauxRef[$pas] ?? end($totauxRef);

            $ecartAbsolu = $valRetenue - $valRef;
            $ecartPct = $valRef > 0 ? round(($ecartAbsolu / $valRef) * 100, 2) : 0.0;

            $ecartCible = null;
            $ecartCiblePct = null;
            if ($cibleMetier !== null && $cibleMetier > 0) {
                $ecartCible = round($valRetenue - $cibleMetier, 0);
                $ecartCiblePct = round(($ecartCible / $cibleMetier) * 100, 2);
            }

            // Vérification des contraintes sur ce pas
            $violationsPas = [];
            foreach ($contraintes as $c) {
                if ($c['type'] === 'plafond_total' && $valRetenue > (float) $c['valeur']) {
                    $violationsPas[] = "Dépassement du plafond ({$valRetenue} > {$c['valeur']})";
                } elseif ($c['type'] === 'plancher_total' && $valRetenue < (float) $c['valeur']) {
                    $violationsPas[] = "Sous le plancher ({$valRetenue} < {$c['valeur']})";
                }
            }

            $lignes[] = [
                'pas' => $pas,
                'annee' => $anneeLibelle,
                'est_annee_source' => ($pas === 0),
                'effectif_reference' => $valRef,
                'effectif_projete' => $valRetenue,
                'ecart_absolu' => $ecartAbsolu,
                'ecart_pourcentage' => $ecartPct,
                'cible_metier' => $cibleMetier,
                'ecart_cible' => $ecartCible,
                'ecart_cible_pourcentage' => $ecartCiblePct,
                'conforme_contraintes' => ($violationsPas === []),
                'violations' => $violationsPas,
            ];
        }

        $dernierRetenu = end($totauxRetenus) ?: $totalInitial;
        $dernierRef = end($totauxRef) ?: $totalInitial;

        return [
            'lignes' => $lignes,
            'synthese_ecarts' => [
                'total_initial' => $totalInitial,
                'total_final_projete' => $dernierRetenu,
                'total_final_reference' => $dernierRef,
                'evolution_globale_absolue' => $dernierRetenu - $totalInitial,
                'evolution_globale_pct' => $totalInitial > 0 ? round((($dernierRetenu - $totalInitial) / $totalInitial) * 100, 2) : 0,
                'ecart_final_sur_reference' => $dernierRetenu - $dernierRef,
            ],
        ];
    }
}
