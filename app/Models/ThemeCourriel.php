<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Thème visuel réutilisable du studio e-mail (identité institutionnelle).
 * Configuration validée côté service (couleurs hexadécimales, largeurs
 * bornées, logo https + extensions autorisées uniquement).
 */
class ThemeCourriel extends Model
{
    protected $table = 'themes_courriel';

    protected $fillable = [
        'nom',
        'config',
        'actif',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'actif' => 'boolean',
        ];
    }
}
