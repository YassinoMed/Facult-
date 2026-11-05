<?php

namespace App\Models\Iam;

use App\Models\Faculte;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Profil d'habilitation personnalisé (LOOP 43-BIS).
 *
 * Un profil N'EST PAS un rôle système : c'est un sous-ensemble borné de
 * permissions administrables, approuvé avant activation, rattaché ou non
 * à une faculté, et versionné. Toute modification incrémente `version` :
 * les affectations existantes conservent l'ancienne version et cessent
 * donc d'accorder l'accès jusqu'à réaffectation explicite.
 */
class ProfilIam extends Model
{
    public const STATUT_BROUILLON = 'brouillon';

    public const STATUT_SOUMIS = 'soumis';

    public const STATUT_ACTIF = 'actif';

    public const STATUT_SUSPENDU = 'suspendu';

    public const STATUT_ARCHIVE = 'archive';

    public const STATUTS = [
        self::STATUT_BROUILLON,
        self::STATUT_SOUMIS,
        self::STATUT_ACTIF,
        self::STATUT_SUSPENDU,
        self::STATUT_ARCHIVE,
    ];

    protected $table = 'iam_profils';

    /** @var array<int, string> */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'approuve_le' => 'datetime',
            'expire_le' => 'datetime',
        ];
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(ProfilPermissionIam::class, 'profil_id');
    }

    public function affectations(): HasMany
    {
        return $this->hasMany(AffectationIam::class, 'profil_id');
    }

    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class);
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par_id');
    }

    public function approbateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approuve_par_id');
    }

    /** @return array<int, string> */
    public function codesPermissions(): array
    {
        return $this->permissions->pluck('permission_code')->all();
    }

    public function estActif(): bool
    {
        return $this->statut === self::STATUT_ACTIF
            && ($this->expire_le === null || $this->expire_le->isFuture());
    }

    public function estModifiable(): bool
    {
        return in_array($this->statut, [self::STATUT_BROUILLON, self::STATUT_SOUMIS], true);
    }
}
