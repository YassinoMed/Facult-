<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modèle d'export réutilisable (§79) : un utilisateur enregistre
 * périmètre + colonnes + format sous un nom, puis le réapplique.
 */
class ExportTemplate extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'type',
        'profil',
        'format',
        'filters',
        'columns',
    ];

    protected $casts = [
        'filters' => 'array',
        'columns' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
