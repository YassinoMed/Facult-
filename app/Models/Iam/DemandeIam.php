<?php

namespace App\Models\Iam;

use App\Models\Faculte;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Demande de gouvernance (quatre yeux). L'initiateur ne peut jamais
 * approuver sa propre demande ; le nombre d'approbations indépendantes
 * requis est lu dans `config('iam.approbateurs_requis')`.
 */
class DemandeIam extends Model
{
    public const TYPE_AFFECTATION_PROFIL = 'affectation_profil';

    public const TYPE_ATTRIBUTION_ROLE = 'attribution_role';

    public const TYPE_DELEGATION = 'delegation';

    public const TYPE_ACTIVATION_PROFIL = 'activation_profil';

    public const TYPE_HABILITATION = 'habilitation';

    public const TYPES = [
        self::TYPE_AFFECTATION_PROFIL,
        self::TYPE_ATTRIBUTION_ROLE,
        self::TYPE_DELEGATION,
        self::TYPE_ACTIVATION_PROFIL,
        self::TYPE_HABILITATION,
    ];

    public const STATUT_SOUMISE = 'soumise';

    public const STATUT_APPROUVEE = 'approuvee';

    public const STATUT_REJETEE = 'rejetee';

    public const STATUT_ANNULEE = 'annulee';

    public const STATUT_EXPIREE = 'expiree';

    protected $table = 'iam_demandes';

    /** @var array<int, string> */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'decide_le' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $demande): void {
            $demande->reference ??= (string) Str::uuid();
        });
    }

    public function initiateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiateur_id');
    }

    public function cible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cible_user_id');
    }

    public function profil(): BelongsTo
    {
        return $this->belongsTo(ProfilIam::class, 'profil_id');
    }

    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class);
    }

    public function approbations(): HasMany
    {
        return $this->hasMany(ApprobationIam::class, 'demande_id');
    }

    public function estApprouvable(): bool
    {
        return $this->statut === self::STATUT_SOUMISE;
    }

    public function libelleType(): string
    {
        return match ($this->type) {
            self::TYPE_AFFECTATION_PROFIL => 'Affectation de profil',
            self::TYPE_ATTRIBUTION_ROLE => 'Attribution de rôle',
            self::TYPE_DELEGATION => 'Délégation de permission',
            self::TYPE_ACTIVATION_PROFIL => 'Activation de profil',
            self::TYPE_HABILITATION => 'Habilitation directe',
            default => (string) $this->type,
        };
    }
}
