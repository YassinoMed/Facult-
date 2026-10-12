<?php

namespace App\Modules\Versioning\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Release — version installable/cohérente du système.
 *
 * États contrôlés : brouillon → preparee → testee → approuvee →
 * en_deploiement → deployee / echouee ; deployee → en_rollback → rollbackee.
 * Les releases déployées/approuvées sont immuables (nouvelle release requise).
 */
class Release extends Model
{
    protected $table = 'releases';

    protected $fillable = [
        'version',
        'statut',
        'environnement',
        'app_version',
        'db_version',
        'db_release',
        'db_schema',
        'dev_version',
        'prod_version',
        'build_number',
        'manifest_hash',
        'compatibility_hash',
        'previous_release_id',
        'notes',
        'changements',
        'created_by',
        'approved_by',
        'deployed_by',
        'deployed_at',
        'rolled_back_at',
        'locked_at',
        'lock_key',
    ];

    protected $casts = [
        'db_schema' => 'array',
        'changements' => 'array',
        'deployed_at' => 'datetime',
        'rolled_back_at' => 'datetime',
        'locked_at' => 'datetime',
    ];

    /* ── Constantes d'état ── */

    public const STATUT_BROUILLON = 'brouillon';

    public const STATUT_PREPAREE = 'preparee';

    public const STATUT_TESTEE = 'testee';

    public const STATUT_APPROUVEE = 'approuvee';

    public const STATUT_EN_DEPLOIEMENT = 'en_deploiement';

    public const STATUT_DEPLOYEE = 'deployee';

    public const STATUT_ECHOUEE = 'echouee';

    public const STATUT_EN_ROLLBACK = 'en_rollback';

    public const STATUT_ROLLBACKEE = 'rollbackee';

    public const STATUT_ARCHIVEE = 'archivee';

    /** Matrice de transitions autorisées. */
    public const TRANSITIONS = [
        self::STATUT_BROUILLON => [self::STATUT_PREPAREE, self::STATUT_ARCHIVEE],
        self::STATUT_PREPAREE => [self::STATUT_TESTEE, self::STATUT_APPROUVEE, self::STATUT_EN_DEPLOIEMENT, self::STATUT_BROUILLON, self::STATUT_ARCHIVEE],
        self::STATUT_TESTEE => [self::STATUT_APPROUVEE, self::STATUT_EN_DEPLOIEMENT, self::STATUT_PREPAREE, self::STATUT_ARCHIVEE],
        self::STATUT_APPROUVEE => [self::STATUT_EN_DEPLOIEMENT, self::STATUT_ARCHIVEE],
        self::STATUT_EN_DEPLOIEMENT => [self::STATUT_DEPLOYEE, self::STATUT_ECHOUEE],
        self::STATUT_DEPLOYEE => [self::STATUT_EN_ROLLBACK, self::STATUT_ARCHIVEE],
        self::STATUT_ECHOUEE => [self::STATUT_PREPAREE, self::STATUT_ARCHIVEE],
        self::STATUT_EN_ROLLBACK => [self::STATUT_ROLLBACKEE, self::STATUT_ECHOUEE],
        self::STATUT_ROLLBACKEE => [self::STATUT_ARCHIVEE],
        self::STATUT_ARCHIVEE => [],
    ];

    /** États considérés comme immuables (plus de modification du contenu). */
    public const STATUTS_IMMUTABLES = [
        self::STATUT_APPROUVEE,
        self::STATUT_EN_DEPLOIEMENT,
        self::STATUT_DEPLOYEE,
        self::STATUT_ROLLBACKEE,
        self::STATUT_ARCHIVEE,
    ];

    public const TOUS_STATUTS = [
        self::STATUT_BROUILLON,
        self::STATUT_PREPAREE,
        self::STATUT_TESTEE,
        self::STATUT_APPROUVEE,
        self::STATUT_EN_DEPLOIEMENT,
        self::STATUT_DEPLOYEE,
        self::STATUT_ECHOUEE,
        self::STATUT_EN_ROLLBACK,
        self::STATUT_ROLLBACKEE,
        self::STATUT_ARCHIVEE,
    ];

    /* ── Relations ── */

    public function releasePrecedente(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_release_id');
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approbateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function deployeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deployed_by');
    }

    public function composants(): HasMany
    {
        return $this->hasMany(VersionComponent::class, 'release_id');
    }

    public function deploiements(): HasMany
    {
        return $this->hasMany(Deployment::class, 'release_id');
    }

    /* ── Helpers ── */

    public function estImmutable(): bool
    {
        return in_array($this->statut, self::STATUTS_IMMUTABLES, true);
    }

    public function peutTransiter(string $vers): bool
    {
        return in_array($vers, self::TRANSITIONS[$this->statut] ?? [], true);
    }

    public function estDeployee(): bool
    {
        return $this->statut === self::STATUT_DEPLOYEE;
    }

    public function estVerrouillee(): bool
    {
        return $this->locked_at !== null && $this->lock_key !== null;
    }

    /**
     * Libellé humain du statut.
     */
    public function getStatutLibelleAttribute(): string
    {
        return match ($this->statut) {
            self::STATUT_BROUILLON => 'Brouillon',
            self::STATUT_PREPAREE => 'Préparée',
            self::STATUT_TESTEE => 'Testée',
            self::STATUT_APPROUVEE => 'Approuvée',
            self::STATUT_EN_DEPLOIEMENT => 'En déploiement',
            self::STATUT_DEPLOYEE => 'Déployée',
            self::STATUT_ECHOUEE => 'Échouée',
            self::STATUT_EN_ROLLBACK => 'En rollback',
            self::STATUT_ROLLBACKEE => 'Rollbackée',
            self::STATUT_ARCHIVEE => 'Archivée',
            default => $this->statut,
        };
    }

    /**
     * Version logique DB (SemVer) avec repli vers db_version.
     */
    public function getDbReleaseAttribute(?string $valeur): string
    {
        return $valeur ?? $this->attributes['db_version'] ?? config('versioning.db_release', '1.0.0');
    }

    /**
     * Libellé humain du schéma DB.
     */
    public function getDbSchemaTexteAttribute(): string
    {
        $schema = $this->db_schema;
        if (is_array($schema)) {
            if (isset($schema['texte'])) {
                return $schema['texte'];
            }
            if (isset($schema['applied'])) {
                return "{$schema['applied']} migrations appliquées";
            }
        }

        return 'Schéma non renseigné';
    }

    /**
     * Couleur CSS du badge de statut.
     */
    public function getStatutCouleurAttribute(): string
    {
        return match ($this->statut) {
            self::STATUT_BROUILLON => 'gray',
            self::STATUT_PREPAREE => 'blue',
            self::STATUT_TESTEE => 'indigo',
            self::STATUT_APPROUVEE => 'purple',
            self::STATUT_EN_DEPLOIEMENT => 'yellow',
            self::STATUT_DEPLOYEE => 'green',
            self::STATUT_ECHOUEE => 'red',
            self::STATUT_EN_ROLLBACK => 'orange',
            self::STATUT_ROLLBACKEE => 'pink',
            self::STATUT_ARCHIVEE => 'slate',
            default => 'gray',
        };
    }
}
