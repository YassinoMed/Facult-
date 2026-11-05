<?php

namespace App\Models\Iam;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne permission d'un profil d'habilitation. Aucune permission arbitraire :
 * le code est validé contre le catalogue canonique avant insertion.
 */
class ProfilPermissionIam extends Model
{
    protected $table = 'iam_profil_permissions';

    /** @var array<int, string> */
    protected $guarded = [];

    public function profil(): BelongsTo
    {
        return $this->belongsTo(ProfilIam::class, 'profil_id');
    }
}
