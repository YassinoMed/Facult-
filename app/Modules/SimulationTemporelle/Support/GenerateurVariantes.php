<?php

namespace App\Modules\SimulationTemporelle\Support;

/**
 * Générateur de variantes REPRODUCTIBLES (ST-01) : pour une graine et
 * des bornes données, la i-ème variante est dérivée de sha256(graine:i)
 * — aucune dépendance à mt_rand/PHP version : reproductible partout,
 * dans les bornes autorisées (jamais de clamp silencieux : les bornes
 * sont validées en amont).
 */
final class GenerateurVariantes
{
    /**
     * Pseudo-aléatoire déterministe dans [-1, 1] à partir d'une graine.
     */
    public static function pseudoAleatoire(string $graine, int $index): float
    {
        $hache = hash('sha256', $graine.':'.$index);

        return (hexdec(substr($hache, 0, 8)) / 0x7FFFFFFF) * 2 - 1;
    }

    /**
     * Variantes du delta d'admissions : la référence (index 0) est le
     * delta de base SAISI ; les suivantes l'entourent de façon bornée.
     *
     * @param  array{min: float, max: float}  $bornes
     * @return array<int, float> index => delta
     */
    public static function deltasAdmissions(float $deltaBase, array $bornes, int $nbVariantes, string $graine): array
    {
        $variantes = [];

        for ($i = 0; $i < $nbVariantes; $i++) {
            if ($i === 0) {
                $variantes[0] = $deltaBase;

                continue;
            }

            $amplitude = min(abs($bornes['max'] - $deltaBase), abs($deltaBase - $bornes['min'])) / 2;
            $valeur = $deltaBase + self::pseudoAleatoire($graine, $i) * $amplitude;
            $variantes[$i] = round(min(max($valeur, $bornes['min']), $bornes['max']), 4);
        }

        return $variantes;
    }
}
