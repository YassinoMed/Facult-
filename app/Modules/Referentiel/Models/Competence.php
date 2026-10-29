<?php

namespace App\Modules\Referentiel\Models;

use App\Models\Faculte;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Compétence attendue du catalogue (Lot 20) : SAISIE par
 * l'administration, jamais seedée. Brouillon révisable (version
 * incrémentée), APPROUVÉE = figée (l'autorité d'approbation est celle
 * des maquettes : `maquettes.approve`), archivable. Toute évolution du
 * libellé passe par archivage + nouvelle compétence — jamais de
 * réinterprétation rétroactive des associations existantes.
 */
class Competence extends Model
{
    public const STATUTS = ['brouillon', 'approuvee', 'archivee'];

    protected $fillable = [
        'code', 'libelle', 'description', 'faculte_id', 'statut',
        'version', 'cree_par', 'approuve_par', 'approuve_le',
    ];

    protected function casts(): array
    {
        return ['approuve_le' => 'datetime'];
    }

    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class);
    }

    public function objectifs(): HasMany
    {
        return $this->hasMany(CompetenceObjectif::class);
    }

    public function estApprouvee(): bool
    {
        return $this->statut === 'approuvee';
    }

    public function estFigee(): bool
    {
        return in_array($this->statut, ['approuvee', 'archivee'], true);
    }

    public function approuvePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approuve_par');
    }
}
