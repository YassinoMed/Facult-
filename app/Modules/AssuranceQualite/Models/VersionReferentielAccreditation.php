<?php

namespace App\Modules\AssuranceQualite\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lot 16 — Version d'un référentiel d'accréditation (catalogue versionné).
 *
 * Cycle : `brouillon` (critères éditables) → `approuvee` (FIGÉE :
 * plus AUCUN ajout/modification de critère — traçabilité de l'évaluation)
 * → `archivee` (historique consultable, plus évaluable).
 */
class VersionReferentielAccreditation extends Model
{
    protected $table = 'versions_referentiel_accreditation';

    public const STATUTS = ['brouillon', 'approuvee', 'archivee'];

    /** @var array<int, string> */
    protected $fillable = [
        'referentiel_id', 'numero', 'statut',
        'motif_figage', 'cree_par', 'approuve_par', 'approuve_le',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['approuve_le' => 'datetime'];
    }

    public function referentiel(): BelongsTo
    {
        return $this->belongsTo(ReferentielAccreditation::class, 'referentiel_id');
    }

    public function criteres(): HasMany
    {
        return $this->hasMany(CritereAccreditation::class, 'version_referentiel_id');
    }

    public function rapports(): HasMany
    {
        return $this->hasMany(RapportAccreditation::class, 'version_referentiel_id');
    }

    public function estFigee(): bool
    {
        return $this->statut !== 'brouillon';
    }

    public function approuvePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approuve_par');
    }
}
