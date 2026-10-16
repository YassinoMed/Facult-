<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Traçabilité du cycle de vie des modèles (création, modification,
 * soumission, publication, archivage, restauration, envoi de test) :
 * acteur + horodatage, détail expurgé (jamais de corps ni de secret).
 */
class JournalModele extends Model
{
    protected $table = 'journal_modeles';

    public const ACTIONS = [
        'cree', 'modifie', 'soumis', 'publie', 'archive', 'restaure', 'envoi_test',
    ];

    protected $fillable = [
        'modele_id',
        'version_numero',
        'action',
        'acteur_id',
        'detail',
    ];

    protected function casts(): array
    {
        return [
            'version_numero' => 'integer',
            'acteur_id' => 'integer',
            'detail' => 'array',
        ];
    }

    public function modele(): BelongsTo
    {
        return $this->belongsTo(ModeleCourriel::class, 'modele_id');
    }
}
