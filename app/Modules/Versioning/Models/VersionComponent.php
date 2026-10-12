<?php

namespace App\Modules\Versioning\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Composant de version (APP, DB, DEV, PROD) — un enregistrement par
 * composant×environnement (unique constraint). Extensible.
 */
class VersionComponent extends Model
{
    protected $table = 'version_components';

    protected $fillable = [
        'composant',
        'version',
        'environnement',
        'release_id',
        'meta',
        'updated_by',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    /* ── Composants connus ── */

    public const COMPOSANT_APP = 'app';

    public const COMPOSANT_DB = 'db';

    public const COMPOSANT_DEV = 'dev';

    public const COMPOSANT_PROD = 'prod';

    public const COMPOSANTS_CONNUS = [
        self::COMPOSANT_APP,
        self::COMPOSANT_DB,
        self::COMPOSANT_DEV,
        self::COMPOSANT_PROD,
    ];

    /* ── Relations ── */

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    public function miseAJourPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /* ── Helpers ── */

    public function getComposantLibelleAttribute(): string
    {
        return match ($this->composant) {
            self::COMPOSANT_APP => 'Application',
            self::COMPOSANT_DB => 'Base de données',
            self::COMPOSANT_DEV => 'Développement',
            self::COMPOSANT_PROD => 'Production',
            default => mb_strtoupper($this->composant),
        };
    }
}
