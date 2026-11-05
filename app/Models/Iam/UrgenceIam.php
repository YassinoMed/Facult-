<?php

namespace App\Models\Iam;

use App\Models\Faculte;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Accès d'urgence (break-glass) : permission temporaire accordée à un
 * bénéficiaire distinct du déclencheur, pour quelques heures, jamais une
 * permission IAM réservée. Une revue indépendante est exigée après coup.
 */
class UrgenceIam extends Model
{
    public const STATUT_ACTIVE = 'active';

    public const STATUT_REVOQUEE = 'revoquee';

    public const STATUT_EXPIREE = 'expiree';

    protected $table = 'iam_urgences';

    /** @var array<int, string> */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'debut_le' => 'datetime',
            'fin_le' => 'datetime',
            'approuve_le' => 'datetime',
            'revue_le' => 'datetime',
        ];
    }

    public function declencheur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'declencheur_id');
    }

    public function beneficiaire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'beneficiaire_id');
    }

    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class);
    }

    public function approbateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approuve_par_id');
    }

    public function revuePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revue_par_id');
    }

    public function estActive(): bool
    {
        return $this->statut === self::STATUT_ACTIVE
            && $this->debut_le->isPast()
            && $this->fin_le->isFuture();
    }
}
