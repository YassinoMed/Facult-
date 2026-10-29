<?php

namespace App\Services\Kpi;

use Illuminate\Support\Facades\Cache;

/**
 * Compteur global d'invalidation des caches KPI/BI.
 *
 * Les empreintes `max(updated_at) + count()` ne détectent pas une écriture
 * qui ne déplace ni le maximum ni le nombre de lignes (mise à jour d'une
 * ligne ancienne avec `timestamps = false`, correction ciblée…). Chaque
 * écriture Eloquent sur une source de vérité incrémente ce compteur, qui
 * entre dans la clé de cache : aucune modification silencieuse ne peut
 * laisser un agrégat périmé jusqu'au TTL.
 *
 * Limite assumée : une écriture effectuée en SQL brut, hors événements
 * Eloquent, n'incrémente pas ce compteur. L'empreinte max/count reste le
 * filet de sécurité pour ce cas.
 */
final class VersionKpi
{
    public const CLE = 'kpi:version-globale';

    public static function courante(): int
    {
        return (int) Cache::get(self::CLE, 0);
    }

    public static function incrementer(): void
    {
        Cache::forever(self::CLE, self::courante() + 1);
    }
}
