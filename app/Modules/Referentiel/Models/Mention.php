<?php

namespace App\Modules\Referentiel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Mention rattachée à un domaine (§6).
 */
class Mention extends Model
{
    protected $fillable = [
        'domaine_id', 'code', 'sigle', 'nom', 'description', 'actif',
    ];

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    public function domaine(): BelongsTo
    {
        return $this->belongsTo(Domaine::class);
    }

    public function specialites(): HasMany
    {
        return $this->hasMany(Specialite::class);
    }
}
