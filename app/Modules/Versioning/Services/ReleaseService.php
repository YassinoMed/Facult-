<?php

namespace App\Modules\Versioning\Services;

use App\Modules\Versioning\Models\AuditVersioning;
use App\Modules\Versioning\Models\Release;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Service de release — CRUD, transitions d'état contrôlées,
 * verrouillage optimiste anti-concurrence. Toute opération
 * importante est auditée via AuditVersioningService.
 */
class ReleaseService
{
    public function __construct(
        private readonly VersioningService $versioning,
        private readonly AuditVersioningService $audit,
    ) {}

    /**
     * Crée une nouvelle release en brouillon.
     */
    public function creer(array $donnees, ?int $utilisateurId = null): Release
    {
        $version = $donnees['version'] ?? $this->versioning->versionApp();

        if (! VersioningService::versionValide($version)) {
            throw new \InvalidArgumentException("Version invalide : {$version}. Format attendu : MAJOR.MINOR.PATCH[-pre].");
        }

        if (Release::where('version', $version)->exists()) {
            throw new \InvalidArgumentException("La version {$version} existe déjà.");
        }

        $precedente = Release::where('statut', Release::STATUT_DEPLOYEE)
            ->latest('deployed_at')
            ->first();

        $dbRelease = $donnees['db_release'] ?? $donnees['db_version'] ?? $this->versioning->dbRelease();
        $dbSchema = $donnees['db_schema'] ?? $this->versioning->dbSchema();

        $release = DB::transaction(function () use ($version, $donnees, $utilisateurId, $precedente, $dbRelease, $dbSchema) {
            return Release::create([
                'version' => $version,
                'statut' => Release::STATUT_BROUILLON,
                'environnement' => $donnees['environnement'] ?? config('versioning.environnement'),
                'app_version' => $donnees['app_version'] ?? $version,
                'db_version' => $dbRelease,
                'db_release' => $dbRelease,
                'db_schema' => $dbSchema,
                'dev_version' => $donnees['dev_version'] ?? null,
                'prod_version' => $donnees['prod_version'] ?? null,
                'build_number' => $donnees['build_number'] ?? $this->versioning->buildCourant(),
                'notes' => $donnees['notes'] ?? null,
                'changements' => $donnees['changements'] ?? null,
                'previous_release_id' => $precedente?->id,
                'created_by' => $utilisateurId ?? auth()->id(),
            ]);
        });

        $this->audit->tracer(
            AuditVersioning::ACTION_RELEASE_CREATED,
            'release',
            $release->id,
            null,
            ['version' => $release->version, 'statut' => $release->statut],
        );

        return $release;
    }

    /**
     * Transition d'état contrôlée — vérifie la matrice TRANSITIONS.
     */
    public function transiter(Release $release, string $nouveauStatut, ?int $utilisateurId = null, ?array $meta = null): Release
    {
        if (! in_array($nouveauStatut, Release::TOUS_STATUTS, true)) {
            throw new \InvalidArgumentException("Statut inconnu : {$nouveauStatut}.");
        }

        if (! $release->peutTransiter($nouveauStatut)) {
            throw new \LogicException(
                "Transition impossible : {$release->statut} → {$nouveauStatut} (release {$release->version})."
            );
        }

        $ancienStatut = $release->statut;

        DB::transaction(function () use ($release, $nouveauStatut, $utilisateurId) {
            $release->statut = $nouveauStatut;

            if ($nouveauStatut === Release::STATUT_APPROUVEE) {
                $release->approved_by = $utilisateurId ?? auth()->id();
            }

            $release->save();
        });

        $this->audit->tracer(
            AuditVersioning::ACTION_RELEASE_TRANSITION,
            'release',
            $release->id,
            ['statut' => $ancienStatut],
            array_merge(['statut' => $nouveauStatut], $meta ?? []),
        );

        return $release->fresh();
    }

    /**
     * Met à jour les notes/changements d'une release (si non immuable).
     */
    public function mettreAJour(Release $release, array $donnees): Release
    {
        if ($release->estImmutable()) {
            throw new \LogicException("Release {$release->version} est immuable ({$release->statut}).");
        }

        $anciennesValeurs = $release->only(['notes', 'changements', 'dev_version']);

        $release->update(array_filter([
            'notes' => $donnees['notes'] ?? $release->notes,
            'changements' => $donnees['changements'] ?? $release->changements,
            'dev_version' => $donnees['dev_version'] ?? $release->dev_version,
        ], fn ($v) => $v !== null));

        $this->audit->tracer(
            AuditVersioning::ACTION_RELEASE_UPDATED,
            'release',
            $release->id,
            $anciennesValeurs,
            $release->only(['notes', 'changements', 'dev_version']),
        );

        return $release->fresh();
    }

    /**
     * Verrouillage optimiste pour les opérations concurrentes.
     * Retourne la clé de verrouillage ou null si déjà verrouillée.
     */
    public function verrouiller(Release $release): ?string
    {
        if ($release->estVerrouillee()) {
            // Vérifier si le verrou a expiré (5 minutes)
            if ($release->locked_at && $release->locked_at->diffInMinutes(now()) < 5) {
                return null;
            }
        }

        $cle = Str::random(64);

        $affecte = Release::where('id', $release->id)
            ->where(function ($q) {
                $q->whereNull('lock_key')
                    ->orWhere('locked_at', '<', now()->subMinutes(5));
            })
            ->update([
                'lock_key' => $cle,
                'locked_at' => now(),
            ]);

        if ($affecte > 0) {
            $release->lock_key = $cle;
            $release->locked_at = now();

            return $cle;
        }

        return null;
    }

    /**
     * Déverrouille une release (avec clé optionnelle pour unlock forcé).
     */
    public function deverrouiller(Release $release, ?string $cle = null): bool
    {
        $query = Release::where('id', $release->id);
        if ($cle !== null) {
            $query->where('lock_key', $cle);
        }

        $res = $query->update(['lock_key' => null, 'locked_at' => null]) > 0;
        if ($res) {
            $release->lock_key = null;
            $release->locked_at = null;
        }

        return $res;
    }

    /**
     * Release actuellement déployée.
     */
    public function releaseDeployee(): ?Release
    {
        return Release::where('statut', Release::STATUT_DEPLOYEE)
            ->latest('deployed_at')
            ->first();
    }

    /**
     * Historique des releases, paginé.
     */
    public function historique(array $filtres = [], int $parPage = 20)
    {
        $query = Release::with(['createur', 'approbateur', 'deployeur'])
            ->orderByDesc('created_at');

        if (! empty($filtres['statut'])) {
            $query->where('statut', $filtres['statut']);
        }
        if (! empty($filtres['environnement'])) {
            $query->where('environnement', $filtres['environnement']);
        }
        if (! empty($filtres['version'])) {
            $query->where('version', 'like', '%'.$filtres['version'].'%');
        }

        return $query->paginate($parPage);
    }

    /**
     * Compare deux releases en détail (LOOP 8 — COMPARAISON).
     *
     * @return array{
     *     source: Release,
     *     cible: Release,
     *     versions: array<string, array{source: ?string, cible: ?string, modifie: bool}>,
     *     manifest_source: ?array,
     *     manifest_cible: ?array,
     *     diff_config: bool,
     *     diff_dependances: bool,
     *     delta_migrations: array{source: int, cible: int, diff: int},
     *     type_evolution: string
     * }
     */
    public function comparer(Release $source, Release $cible): array
    {
        $manifestSource = app(ManifestService::class)->charger($source->version);
        $manifestCible = app(ManifestService::class)->charger($cible->version);

        $versions = [
            'app_version' => ['source' => $source->app_version, 'cible' => $cible->app_version, 'modifie' => $source->app_version !== $cible->app_version],
            'db_version' => ['source' => $source->db_version, 'cible' => $cible->db_version, 'modifie' => $source->db_version !== $cible->db_version],
            'dev_version' => ['source' => $source->dev_version, 'cible' => $cible->dev_version, 'modifie' => $source->dev_version !== $cible->dev_version],
            'prod_version' => ['source' => $source->prod_version, 'cible' => $cible->prod_version, 'modifie' => $source->prod_version !== $cible->prod_version],
            'build_number' => ['source' => $source->build_number, 'cible' => $cible->build_number, 'modifie' => $source->build_number !== $cible->build_number],
        ];

        // Comparaison manifests
        $configHashSource = $manifestSource['config_hash'] ?? null;
        $configHashCible = $manifestCible['config_hash'] ?? null;
        $diffConfig = ($configHashSource !== null && $configHashCible !== null)
            ? ! hash_equals($configHashSource, $configHashCible)
            : false;

        $lockHashSource = $manifestSource['dependencies']['lock_hash'] ?? null;
        $lockHashCible = $manifestCible['dependencies']['lock_hash'] ?? null;
        $diffDeps = ($lockHashSource !== null && $lockHashCible !== null)
            ? ! hash_equals((string) $lockHashSource, (string) $lockHashCible)
            : false;

        $migSource = (int) ($manifestSource['migrations']['applied'] ?? 0);
        $migCible = (int) ($manifestCible['migrations']['applied'] ?? 0);

        // Déterminer le type d'évolution (majeure, mineure, correctif, régression)
        $vSource = VersioningService::analyserVersion($source->version);
        $vCible = VersioningService::analyserVersion($cible->version);

        $typeEvolution = 'inconnue';
        if ($vSource && $vCible) {
            if ($vCible['major'] > $vSource['major']) {
                $typeEvolution = 'majeure';
            } elseif ($vCible['major'] < $vSource['major']) {
                $typeEvolution = 'regression_majeure';
            } elseif ($vCible['minor'] > $vSource['minor']) {
                $typeEvolution = 'mineure';
            } elseif ($vCible['minor'] < $vSource['minor']) {
                $typeEvolution = 'regression_mineure';
            } elseif ($vCible['patch'] > $vSource['patch']) {
                $typeEvolution = 'correctif';
            } elseif ($vCible['patch'] < $vSource['patch']) {
                $typeEvolution = 'regression_correctif';
            } else {
                $typeEvolution = 'identique';
            }
        }

        return [
            'source' => $source,
            'cible' => $cible,
            'versions' => $versions,
            'manifest_source' => $manifestSource,
            'manifest_cible' => $manifestCible,
            'diff_config' => $diffConfig,
            'diff_dependances' => $diffDeps,
            'delta_migrations' => [
                'source' => $migSource,
                'cible' => $migCible,
                'diff' => $migCible - $migSource,
            ],
            'type_evolution' => $typeEvolution,
        ];
    }
}
