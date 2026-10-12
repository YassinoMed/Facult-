<?php

namespace App\Modules\Sentinel\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Platform Sentinel — surcharge locale d'un contrôle enregistré
 * (activation, gravité, seuils). Les défauts vivent dans le code
 * (registre) ; la table ne porte que les écarts, jamais de secret.
 */
class SentinelRegle extends Model
{
    protected $table = 'sentinel_regles';

    protected $fillable = [
        'code', 'categorie', 'gravite', 'module', 'actif', 'seuil',
    ];

    protected function casts(): array
    {
        return ['actif' => 'boolean', 'seuil' => 'array'];
    }
}
