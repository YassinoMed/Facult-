<?php

namespace App\Modules\Enseignement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Type d'enseignement configurable (§6) : CM, TD, TP, Projet…
 */
class TypeEnseignement extends Model
{
    protected $table = 'types_enseignement';

    protected $fillable = ['code', 'nom', 'description', 'actif'];

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    public function enseignements(): HasMany
    {
        return $this->hasMany(Enseignement::class, 'type_id');
    }
}
