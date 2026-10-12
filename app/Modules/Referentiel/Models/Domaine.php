<?php

namespace App\Modules\Referentiel\Models;

use App\Models\Faculte;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Domaine de formation d'un établissement (§5).
 */
class Domaine extends Model
{
    protected $fillable = [
        'faculte_id', 'code', 'sigle', 'nom', 'description', 'actif',
    ];

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class);
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(Mention::class);
    }
}
