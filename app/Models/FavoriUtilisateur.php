<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Favori épinglé (Phase 7 §7.4) : entité ou page, par utilisateur.
 * Le lien pointe vers une page déjà gated — aucune autorisation ici.
 */
class FavoriUtilisateur extends Model
{
    protected $table = 'user_favoris';

    protected $fillable = [
        'user_id', 'type', 'reference', 'titre', 'lien',
    ];

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
