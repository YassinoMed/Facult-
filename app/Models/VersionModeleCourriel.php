<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Version immuable d'un schéma de modèle e-mail : chaque sauvegarde
 * explicite crée un nouveau numéro. Retour arrière = publier une copie
 * d'une version antérieure sous un nouveau numéro (historique préservé).
 */
class VersionModeleCourriel extends Model
{
    protected $table = 'versions_modele_courriel';

    protected $fillable = [
        'modele_id',
        'numero',
        'schema',
        'auteur_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'numero' => 'integer',
            'schema' => 'array',
            'auteur_id' => 'integer',
        ];
    }

    public function modele(): BelongsTo
    {
        return $this->belongsTo(ModeleCourriel::class, 'modele_id');
    }
}
