<?php

namespace App\Modules\Versioning\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Déploiement — trace d'une opération deploy ou rollback.
 * Un déploiement est immuable une fois terminé (append-only).
 */
class Deployment extends Model
{
    public $timestamps = false;

    protected $table = 'deployments';

    protected $fillable = [
        'release_id',
        'type',
        'environnement',
        'statut',
        'started_at',
        'finished_at',
        'duree_secondes',
        'deployed_by',
        'backup_path',
        'erreur',
        'meta',
        'created_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'created_at' => 'datetime',
        'duree_secondes' => 'integer',
    ];

    /* ── Types ── */

    public const TYPE_DEPLOY = 'deploy';

    public const TYPE_ROLLBACK = 'rollback';

    /* ── Statuts ── */

    public const STATUT_EN_ATTENTE = 'en_attente';

    public const STATUT_EN_COURS = 'en_cours';

    public const STATUT_REUSSI = 'reussi';

    public const STATUT_ECHOUE = 'echoue';

    /* ── Relations ── */

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    public function deployeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deployed_by');
    }

    /* ── Helpers ── */

    public function estTermine(): bool
    {
        return in_array($this->statut, [self::STATUT_REUSSI, self::STATUT_ECHOUE], true);
    }

    public function getTypeLibelleAttribute(): string
    {
        return match ($this->type) {
            self::TYPE_DEPLOY => 'Déploiement',
            self::TYPE_ROLLBACK => 'Rollback',
            default => $this->type,
        };
    }

    public function getStatutLibelleAttribute(): string
    {
        return match ($this->statut) {
            self::STATUT_EN_ATTENTE => 'En attente',
            self::STATUT_EN_COURS => 'En cours',
            self::STATUT_REUSSI => 'Réussi',
            self::STATUT_ECHOUE => 'Échoué',
            default => $this->statut,
        };
    }
}
