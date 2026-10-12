<?php

namespace App\Modules\Versioning\Services;

use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Manifest de release — génération, vérification d'intégrité, stockage.
 *
 * Le manifest décrit l'état complet d'une release sans jamais
 * inclure de secret (APP_KEY, mots de passe, tokens). Les variables
 * sensibles sont exclues ou remplacées par un hash non réversible.
 */
class ManifestService
{
    /** Variables d'environnement à exclure du manifest. */
    private const SECRETS = [
        'APP_KEY', 'DB_PASSWORD', 'REDIS_PASSWORD',
        'MAIL_PASSWORD', 'AWS_SECRET_ACCESS_KEY',
        'INTEGRATION_WEBHOOK_SECRET', 'SANCTUM_TOKEN_PREFIX',
    ];

    /**
     * Génère le manifest complet pour une release donnée.
     *
     * @return array<string, mixed>
     */
    public function generer(array $releaseData): array
    {
        $dbRelease = $releaseData['db_release'] ?? $releaseData['db_version'] ?? '';
        $dbSchema = $releaseData['db_schema'] ?? null;
        if ($dbSchema === null) {
            $etat = $this->etatMigrations();
            $dbSchema = [
                'applied' => $etat['applied'],
                'pending' => $etat['pending'],
                'last' => $etat['last'],
                'texte' => "{$etat['applied']} migrations appliquées",
            ];
        }

        $manifest = [
            'generated_at' => now()->toIso8601String(),
            'release_version' => $releaseData['version'] ?? '',
            'app_version' => $releaseData['app_version'] ?? '',
            'db_release' => $dbRelease,
            'db_version' => $dbRelease,
            'db_schema' => $dbSchema,
            'dev_version' => $releaseData['dev_version'] ?? null,
            'prod_version' => $releaseData['prod_version'] ?? null,
            'build_number' => $releaseData['build_number'] ?? '',
            'environnement' => config('versioning.environnement'),
            'framework' => [
                'laravel' => app()->version(),
                'php' => PHP_VERSION,
            ],
            'dependencies' => $this->dependancesComposer(),
            'frontend' => $this->dependancesFrontend(),
            'migrations' => $this->etatMigrations(),
            'modules_actifs' => $this->modulesActifs(),
            'config_hash' => $this->hashConfigurationSecurisee(),
            'notes' => $releaseData['notes'] ?? null,
        ];

        // Hash d'intégrité du manifest lui-même (sans le champ hash)
        $manifest['manifest_hash'] = hash('sha256', json_encode($manifest, JSON_UNESCAPED_UNICODE));

        return $manifest;
    }

    /**
     * Stocke le manifest en JSON dans le disque local.
     */
    public function stocker(array $manifest, string $version): string
    {
        $chemin = config('versioning.stockage.manifests')."/{$version}.json";
        $contenu = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        Storage::disk(config('versioning.stockage.disque'))->put($chemin, $contenu);

        return $chemin;
    }

    /**
     * Charge un manifest stocké.
     *
     * @return array<string, mixed>|null
     */
    public function charger(string $version): ?array
    {
        $chemin = config('versioning.stockage.manifests')."/{$version}.json";
        $disque = Storage::disk(config('versioning.stockage.disque'));

        if (! $disque->exists($chemin)) {
            return null;
        }

        return json_decode($disque->get($chemin), true);
    }

    /**
     * Vérifie l'intégrité d'un manifest stocké.
     */
    public function verifierIntegrite(array $manifest): bool
    {
        $hashStocke = $manifest['manifest_hash'] ?? null;

        if ($hashStocke === null) {
            return false;
        }

        // Recalculer sans le champ hash
        $copie = $manifest;
        unset($copie['manifest_hash']);
        $hashRecalcule = hash('sha256', json_encode($copie, JSON_UNESCAPED_UNICODE));

        return hash_equals($hashStocke, $hashRecalcule);
    }

    /**
     * Hash du composer.lock (dépendances exactes, sans exposer le contenu).
     */
    private function dependancesComposer(): array
    {
        $lockFile = base_path('composer.lock');

        return [
            'lock_hash' => is_file($lockFile) ? hash_file('sha256', $lockFile) : null,
            'lock_exists' => is_file($lockFile),
        ];
    }

    /**
     * Hash du package-lock.json.
     */
    private function dependancesFrontend(): array
    {
        $lockFile = base_path('package-lock.json');

        return [
            'lock_hash' => is_file($lockFile) ? hash_file('sha256', $lockFile) : null,
            'lock_exists' => is_file($lockFile),
        ];
    }

    /**
     * État des migrations Laravel.
     *
     * @return array{applied: int, pending: int, last: string|null}
     */
    private function etatMigrations(): array
    {
        try {
            $appliquees = DB::table('migrations')->pluck('migration')->all();
            $toutesLes = $this->migrationsFichiers();
            $enAttente = array_diff($toutesLes, $appliquees);

            return [
                'applied' => count($appliquees),
                'pending' => count($enAttente),
                'last' => end($appliquees) ?: null,
                'pending_list' => array_values($enAttente),
            ];
        } catch (\Throwable) {
            return ['applied' => 0, 'pending' => 0, 'last' => null, 'pending_list' => []];
        }
    }

    /**
     * @return array<int, string>
     */
    private function migrationsFichiers(): array
    {
        $dossier = database_path('migrations');
        $fichiers = [];

        if (is_dir($dossier)) {
            foreach (scandir($dossier) as $f) {
                if (str_ends_with($f, '.php')) {
                    $fichiers[] = pathinfo($f, PATHINFO_FILENAME);
                }
            }
        }

        sort($fichiers);

        return $fichiers;
    }

    /**
     * Liste des modules actifs.
     *
     * @return array<int, string>
     */
    private function modulesActifs(): array
    {
        try {
            $registry = app(ModuleRegistry::class);

            return $registry->modulesActifs();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Hash de la configuration non sensible — empreinte reproductible
     * pour détecter un changement de configuration entre releases.
     */
    private function hashConfigurationSecurisee(): string
    {
        $clesVerifiees = [
            'app.env', 'app.debug', 'app.locale',
            'database.default',
            'cache.default',
            'queue.default',
            'session.driver',
        ];

        $donnees = [];
        foreach ($clesVerifiees as $cle) {
            $donnees[$cle] = config($cle);
        }

        return hash('sha256', json_encode($donnees, JSON_UNESCAPED_UNICODE));
    }
}
