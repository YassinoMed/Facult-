<?php

namespace App\Models\Iam;

use App\Models\Faculte;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Délégation bornée d'une permission. Le moteur vérifie à CHAQUE
 * évaluation que le délégant détient encore directement la permission :
 * une délégation ne survit pas au retrait du pouvoir du délégant et ne
 * peut jamais être re-déléguée (pas de chaîne).
 */
class DelegationIam extends Model
{
    public const STATUT_ACTIVE = 'active';

    public const STATUT_REVOQUEE = 'revoquee';

    public const STATUT_EXPIREE = 'expiree';

    protected $table = 'iam_delegations';

    /** @var array<int, string> */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'debut_le' => 'datetime',
            'fin_le' => 'datetime',
            'revoque_le' => 'datetime',
        ];
    }

    public function delegant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegant_id');
    }

    public function delegataire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegataire_id');
    }

    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class);
    }

    public function estActive(): bool
    {
        return $this->statut === self::STATUT_ACTIVE
            && $this->debut_le->isPast()
            && $this->fin_le->isFuture();
    }
}
