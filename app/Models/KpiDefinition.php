<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * LOT 03 — Ligne du registre persistant des définitions KPI.
 *
 * Miroir versionné des définitions documentées en code (`BIService` pour
 * la BI centrale, `App\Services\Kpi\CatalogueKpi` pour le cockpit) :
 * la version s'incrémente quand la définition/source change, et la ligne
 * porte le dernier snapshot de valeur calculé par pierre BI.
 *
 * Documentation pure : aucune écriture métier, aucune décision
 * automatique — descriptive uniquement.
 *
 * FAIL-SOFT (pattern LOT 05 `NoteEpreuve::existeColonneValideAt`) : la
 * table provient d'une migration additive (2026_10_24_110000). Tant
 * qu'elle n'est pas appliquée dans un environnement, les lecteurs se
 * replient proprement (registre absent = synchronisation ignorée,
 * jamais d'erreur SQL au rendu).
 *
 * @property int $id
 * @property string $cle
 * @property string $provenance
 * @property string|null $nom
 * @property string $definition
 * @property string|null $source
 * @property string|null $unite
 * @property string|null $categorie
 * @property int $version
 * @property string $definition_hash
 * @property bool $actif
 * @property array|null $dernier_snapshot
 * @property Carbon|null $dernier_snapshot_a
 */
class KpiDefinition extends Model
{
    protected static ?bool $tableDisponibleMemoisee = null;

    protected $table = 'kpi_definitions';

    protected $fillable = [
        'cle',
        'provenance',
        'nom',
        'definition',
        'source',
        'unite',
        'categorie',
        'version',
        'definition_hash',
        'actif',
        'dernier_snapshot',
        'dernier_snapshot_a',
    ];

    protected $casts = [
        'version' => 'integer',
        'actif' => 'boolean',
        'dernier_snapshot' => 'array',
        'dernier_snapshot_a' => 'datetime',
    ];

    /**
     * Disponibilité de la table (mémoïsée par requête). `$fresh = true`
     * force la re-détection (migrations appliquées à chaud / tests).
     */
    public static function tableDisponible(bool $fresh = false): bool
    {
        if ($fresh) {
            self::$tableDisponibleMemoisee = null;
        }

        if (self::$tableDisponibleMemoisee === null) {
            self::$tableDisponibleMemoisee = Schema::hasTable((new self)->getTable());
        }

        return self::$tableDisponibleMemoisee;
    }
}
