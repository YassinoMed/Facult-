<?php

namespace App\Modules\Versioning\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Journal d'audit versioning — append-only, jamais de secrets.
 *
 * Séparé de `historique_academique` qui exige un `etudiant_id` FK
 * et concerne les opérations sur les dossiers étudiants.
 */
class AuditVersioning extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'audit_versioning';

    protected $fillable = [
        'utilisateur_id',
        'action',
        'entite',
        'entite_id',
        'ancienne_valeur',
        'nouvelle_valeur',
        'environnement',
        'adresse_ip',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'ancienne_valeur' => 'array',
        'nouvelle_valeur' => 'array',
        'created_at' => 'datetime',
    ];

    /* ── Actions connues ── */

    public const ACTION_RELEASE_CREATED = 'release_created';

    public const ACTION_RELEASE_UPDATED = 'release_updated';

    public const ACTION_RELEASE_TRANSITION = 'release_transition';

    public const ACTION_RELEASE_APPROVED = 'release_approved';

    public const ACTION_DEPLOYMENT_STARTED = 'deployment_started';

    public const ACTION_DEPLOYMENT_SUCCEEDED = 'deployment_succeeded';

    public const ACTION_DEPLOYMENT_FAILED = 'deployment_failed';

    public const ACTION_ROLLBACK_STARTED = 'rollback_started';

    public const ACTION_ROLLBACK_SUCCEEDED = 'rollback_succeeded';

    public const ACTION_ROLLBACK_FAILED = 'rollback_failed';

    public const ACTION_COMPATIBILITY_CHECK = 'compatibility_check';

    public const ACTION_DRIFT_DETECTED = 'drift_detected';

    public const ACTION_MANIFEST_GENERATED = 'manifest_generated';

    public const ACTION_BACKUP_CREATED = 'backup_created';

    public const ACTION_VERSION_UPDATED = 'version_updated';

    /* ── Relations ── */

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'utilisateur_id');
    }

    /* ── Scopes ── */

    public function scopePourRelease($query, int $releaseId)
    {
        return $query->where('entite', 'release')->where('entite_id', $releaseId);
    }

    public function scopePourAction($query, string $action)
    {
        return $query->where('action', $action);
    }
}
