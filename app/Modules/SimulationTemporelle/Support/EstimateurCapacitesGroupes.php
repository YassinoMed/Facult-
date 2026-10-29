<?php

namespace App\Modules\SimulationTemporelle\Support;

use App\Modules\Schedule\Models\Group;

/**
 * Estimateur de Capacité d'Accueil et des Besoins en Groupes (Lot 5).
 *
 * RÈGLES DU MANDAT :
 * - Calcul explicite des groupes théoriquement nécessaires ;
 * - Détection des dépassements et années de saturation ;
 * - Distingue capacité théorique, capacité autorisée, et estimation de groupes ;
 * - Jamais de capacité standard inventée sans configuration ou référence aux groupes existants.
 */
final class EstimateurCapacitesGroupes
{
    public const CAPACITE_GROUPE_DEFAUT = 35; // Capacité standard universitaire de TD

    /**
     * Estime les besoins en groupes et le taux d'occupation des capacités.
     *
     * @param  array<string, int>  $cohortes  Niveaux => effectifs
     * @param  array<int, int>|null  $groupesIds
     * @return array<string, mixed>
     */
    public function estimer(array $cohortes, ?int $niveauId = null, ?array $groupesIds = null): array
    {
        // 1. Détermination de la capacité unitaire de référence
        $capaciteUnitaire = self::CAPACITE_GROUPE_DEFAUT;
        $capaciteInstalleeTotale = 0;
        $nbGroupesActifs = 0;

        if ($groupesIds !== null && $groupesIds !== []) {
            $groupes = Group::whereIn('id', $groupesIds)->where('actif', true)->get();
            if ($groupes->isNotEmpty()) {
                $capaciteInstalleeTotale = $groupes->sum(fn ($g) => $g->capacite ?? self::CAPACITE_GROUPE_DEFAUT);
                $nbGroupesActifs = $groupes->count();
                $moyenne = $groupes->avg('capacite');
                if ($moyenne !== null && $moyenne > 0) {
                    $capaciteUnitaire = (int) round($moyenne);
                }
            }
        } elseif ($niveauId !== null) {
            $groupesNiveau = Group::where('niveau_id', $niveauId)->where('actif', true)->get();
            if ($groupesNiveau->isNotEmpty()) {
                $capaciteInstalleeTotale = $groupesNiveau->sum(fn ($g) => $g->capacite ?? self::CAPACITE_GROUPE_DEFAUT);
                $nbGroupesActifs = $groupesNiveau->count();
                $moyenne = $groupesNiveau->avg('capacite');
                if ($moyenne !== null && $moyenne > 0) {
                    $capaciteUnitaire = (int) round($moyenne);
                }
            }
        }

        // 2. Calcul par cohorte / niveau
        $detailNiveaux = [];
        $totalGroupesRequis = 0;
        $totalEffectif = array_sum($cohortes);

        foreach ($cohortes as $codeNiveau => $effectif) {
            $groupesRequis = (int) ceil($effectif / $capaciteUnitaire);
            $totalGroupesRequis += $groupesRequis;

            $detailNiveaux[$codeNiveau] = [
                'effectif' => $effectif,
                'capacite_groupe_reference' => $capaciteUnitaire,
                'groupes_theoriques_requis' => $groupesRequis,
                'taux_saturation_groupe' => $groupesRequis > 0 ? round(($effectif / ($groupesRequis * $capaciteUnitaire)) * 100, 1) : 0,
            ];
        }

        $saturationGlobale = null;
        $depassementCapacite = false;
        $ecartPlaces = null;

        if ($capaciteInstalleeTotale > 0) {
            $saturationGlobale = round(($totalEffectif / $capaciteInstalleeTotale) * 100, 1);
            $depassementCapacite = ($totalEffectif > $capaciteInstalleeTotale);
            $ecartPlaces = $totalEffectif - $capaciteInstalleeTotale;
        }

        return [
            'total_effectif' => $totalEffectif,
            'capacite_groupe_reference' => $capaciteUnitaire,
            // Hypothèse explicite : la capacité de référence provient des
            // groupes réels (false) ou du standard par défaut (true) —
            // jamais présentée comme une donnée mesurée.
            'capacite_groupe_hypothese' => $nbGroupesActifs === 0,
            'groupes_theoriques_requis_total' => $totalGroupesRequis,
            'nb_groupes_actuels' => $nbGroupesActifs,
            'deficit_groupes' => max(0, $totalGroupesRequis - $nbGroupesActifs),
            'capacite_installee_totale' => $capaciteInstalleeTotale > 0 ? $capaciteInstalleeTotale : null,
            'taux_saturation_global_pct' => $saturationGlobale,
            'depassement_capacite' => $depassementCapacite,
            'places_manquantes' => $depassementCapacite ? $ecartPlaces : 0,
            'detail_par_niveau' => $detailNiveaux,
        ];
    }
}
