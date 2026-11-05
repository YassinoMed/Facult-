<?php

namespace App\Models\Iam;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne de recertification : un accès existant doit être confirmé ou
 * retiré par un acteur distinct de son bénéficiaire.
 */
class RecertificationIam extends Model
{
    public const STATUT_A_FAIRE = 'a_faire';

    public const STATUT_CONFIRMEE = 'confirmee';

    public const STATUT_RETIREE = 'retiree';

    public const STATUT_EXPIREE = 'expiree';

    public const OBJET_AFFECTATION = 'affectation';

    public const OBJET_HABILITATION = 'habilitation';

    public const OBJET_DELEGATION = 'delegation';

    protected $table = 'iam_recertifications';

    /** @var array<int, string> */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'echeance_le' => 'datetime',
            'decide_le' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function assigneA(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigne_a_id');
    }

    public function decidePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decide_par_id');
    }

    public function libelleObjet(): string
    {
        return match ($this->objet_type) {
            self::OBJET_AFFECTATION => 'Affectation de profil',
            self::OBJET_HABILITATION => 'Habilitation directe',
            self::OBJET_DELEGATION => 'Délégation',
            default => (string) $this->objet_type,
        };
    }
}
