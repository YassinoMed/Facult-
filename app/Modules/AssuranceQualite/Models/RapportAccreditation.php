<?php

namespace App\Modules\AssuranceQualite\Models;

use App\Models\Faculte;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lot 16 — Rapport d'auto-évaluation (workflow d'approbation).
 *
 * Cycle : `brouillon` → `soumis` (complétude EXIGÉE : chaque critère du
 * périmètre porte un constat EXPLICITE ; totaux FIGÉS à la soumission) →
 * `approuve` / `rejete` (approbateur ≠ soumetteur ; motif requis au rejet).
 *
 * INTERDIT DU CAHIER : présenter un rapport incomplet comme conforme —
 * un critère `non_evalue` est compté comme TEL dans les totaux figés,
 * jamais comme conforme.
 */
class RapportAccreditation extends Model
{
    protected $table = 'rapports_accreditation';

    public const STATUTS = ['brouillon', 'soumis', 'approuve', 'rejete'];

    /** Champs de totaux figés à la soumission. */
    public const TOTAUX = [
        'total_criteres', 'total_conformes', 'total_partiels',
        'total_non_conformes', 'total_non_evalues',
    ];

    /** @var array<int, string> */
    protected $fillable = [
        'version_referentiel_id', 'perimetre', 'faculte_id', 'statut',
        'soumis_par', 'soumis_le', 'approuve_par', 'approuve_le',
        'commentaire_approbation', 'motif_rejet',
        'total_criteres', 'total_conformes', 'total_partiels',
        'total_non_conformes', 'total_non_evalues',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['soumis_le' => 'datetime', 'approuve_le' => 'datetime'];
    }

    public static function perimetrePour(?int $faculteId): string
    {
        return $faculteId === null ? 'global' : 'faculte:'.$faculteId;
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(VersionReferentielAccreditation::class, 'version_referentiel_id');
    }

    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class);
    }

    public function soumisPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'soumis_par');
    }

    public function approuvePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approuve_par');
    }
}
