<?php

namespace App\Modules\Referentiel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Spécialité rattachée à une mention (§7).
 */
class Specialite extends Model
{
    protected $fillable = [
        'mention_id', 'code', 'nom', 'description', 'actif',
    ];

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    public function mention(): BelongsTo
    {
        return $this->belongsTo(Mention::class);
    }

    public function programmes(): HasMany
    {
        return $this->hasMany(Programme::class);
    }
}
