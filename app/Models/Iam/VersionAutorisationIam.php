<?php

namespace App\Models\Iam;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Compteur de version d'autorisation par compte. Toute mutation de
 * gouvernance l'incrémente : les autres processus (et le cache mémoire
 * par requête) détectent immédiatement le changement.
 */
class VersionAutorisationIam extends Model
{
    protected $table = 'iam_versions_autorisation';

    /** @var array<int, string> */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'calcule_le' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
