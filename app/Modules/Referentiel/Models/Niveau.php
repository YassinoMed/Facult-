<?php

namespace App\Modules\Referentiel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Niveau d'un programme — L1..D3, ordre pédagogique (§10).
 */
class Niveau extends Model
{
    protected $table = 'niveaux';

    protected $fillable = [
        'programme_id', 'code', 'nom', 'ordre', 'semestres_nombre',
        'credits_attendus', 'actif',
    ];

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    public function semestres(): HasMany
    {
        return $this->hasMany(SemestreLmd::class)->orderBy('ordre');
    }
}
