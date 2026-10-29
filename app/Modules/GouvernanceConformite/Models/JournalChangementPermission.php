<?php

namespace App\Modules\GouvernanceConformite\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lot 19 — Trace structurée d'un changement de permissions (rôle).
 *
 * Écrite par l'Observer User (créations de comptes et changements de rôle) :
 * AUCUN contrôleur existant n'est modifié — l'attribution existante
 * (`AdministrateurController`) reste la seule voie d'écriture des rôles,
 * le journal en capture le résultat, avec l'acteur HTTP si disponible.
 */
class JournalChangementPermission extends Model
{
    protected $table = 'journal_changements_permissions';

    /** @var array<int, string> */
    protected $fillable = [
        'utilisateur_id', 'ancien_role', 'nouveau_role', 'acteur_id', 'source',
    ];

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'utilisateur_id');
    }

    public function acteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acteur_id');
    }
}
