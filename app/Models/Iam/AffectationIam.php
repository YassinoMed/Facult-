<?php

namespace App\Models\Iam;

use App\Models\Faculte;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Affectation d'un profil — ou d'un rôle système — à un compte, pour une
 * fenêtre bornée. `version_profil` fige la version approuvée : une
 * modification ultérieure du profil n'élargit jamais l'affectation.
 *
 * Cette table ne remplace PAS `users.role` : une affectation de rôle est
 * exécutée par `AttributionRoleService` (même source de vérité, mêmes
 * gardes anti-escalade et dernier super-admin).
 */
class AffectationIam extends Model
{
    public const STATUT_ACTIVE = 'active';

    public const STATUT_REVOQUEE = 'revoquee';

    public const STATUT_EXPIREE = 'expiree';

    protected $table = 'iam_affectations';

    /** @var array<int, string> */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'debut_le' => 'datetime',
            'expire_le' => 'datetime',
            'revoque_le' => 'datetime',
            'version_profil' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function profil(): BelongsTo
    {
        return $this->belongsTo(ProfilIam::class, 'profil_id');
    }

    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class);
    }

    public function demande(): BelongsTo
    {
        return $this->belongsTo(DemandeIam::class, 'demande_id');
    }

    public function creePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par_id');
    }

    public function revoquePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoque_par_id');
    }

    public function estActive(): bool
    {
        return $this->statut === self::STATUT_ACTIVE
            && $this->debut_le !== null
            && $this->debut_le->isPast()
            && ($this->expire_le === null || $this->expire_le->isFuture());
    }
}
