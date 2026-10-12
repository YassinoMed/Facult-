<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fonction du personnel administratif, configurable (§14).
 */
class Fonction extends Model
{
    protected $fillable = ['code', 'nom', 'actif'];

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    public function personnels(): HasMany
    {
        return $this->hasMany(Personnel::class);
    }
}
