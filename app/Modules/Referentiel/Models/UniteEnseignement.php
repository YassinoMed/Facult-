<?php

namespace App\Modules\Referentiel\Models;

use App\Models\Matiere;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Unité d'enseignement : regroupe des ECUE (`Matiere`) (§13).
 */
class UniteEnseignement extends Model
{
    protected $table = 'unites_enseignement';

    public const TYPES = ['fondamentale', 'transversale', 'optionnelle', 'professionnelle'];

    protected $fillable = [
        'semestre_id', 'programme_id', 'code', 'sigle', 'nom', 'description',
        'credits', 'coefficient', 'type', 'ordre', 'actif',
    ];

    protected function casts(): array
    {
        return ['coefficient' => 'decimal:2', 'actif' => 'boolean'];
    }

    public function semestre(): BelongsTo
    {
        return $this->belongsTo(SemestreLmd::class, 'semestre_id');
    }

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    /**
     * ECUE de l'UE : les matières rattachées.
     *
     * @return HasMany<Matiere, $this>
     */
    public function ecues(): HasMany
    {
        return $this->hasMany(Matiere::class, 'ue_id')->orderBy('code');
    }
}
