<?php

namespace App\Models\Iam;

use App\Models\Faculte;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Habilitation directe d'une permission unitaire, bornée dans le temps.
 * Jamais une permission réservée (module IAM) ni une permission fédérée
 * en lecture seule.
 */
class HabilitationIam extends Model
{
    public const STATUT_ACTIVE = 'active';

    public const STATUT_REVOQUEE = 'revoquee';

    public const STATUT_EXPIREE = 'expiree';

    protected $table = 'iam_habilitations';

    /** @var array<int, string> */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'debut_le' => 'datetime',
            'expire_le' => 'datetime',
            'revoque_le' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class);
    }

    public function estActive(): bool
    {
        return $this->statut === self::STATUT_ACTIVE
            && $this->debut_le !== null
            && $this->debut_le->isPast()
            && ($this->expire_le === null || $this->expire_le->isFuture());
    }
}
