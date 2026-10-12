<?php

namespace App\Modules\Referentiel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Semestre LMD d'un niveau — jamais confondu avec le semestre
 * d'inscription étudiant (`App\Models\Semestre`) (§12).
 */
class SemestreLmd extends Model
{
    protected $table = 'semestres_lmd';

    protected $fillable = [
        'niveau_id', 'code', 'nom', 'numero', 'ordre', 'credits_attendus', 'actif',
    ];

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    public function niveau(): BelongsTo
    {
        return $this->belongsTo(Niveau::class);
    }

    public function unitesEnseignement(): HasMany
    {
        return $this->hasMany(UniteEnseignement::class, 'semestre_id')->orderBy('code');
    }
}
