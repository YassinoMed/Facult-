<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Version immuable d'un paramètre (LOOP F, append-only) : chaque écriture
 * effective et chaque restauration créent une version. Aucun UPDATE ni
 * DELETE applicatif (convention verrouillée par test).
 */
class ParametreVersion extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'parametre_versions';

    protected $fillable = [
        'parametre_id',
        'valeur',
        'valeur_precedente',
        'acteur_id',
        'motif',
    ];

    public function parametre(): BelongsTo
    {
        return $this->belongsTo(Parametre::class);
    }

    public function acteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acteur_id');
    }
}
