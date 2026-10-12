<?php

namespace App\Console\Commands;

use App\Modules\Versioning\Models\Release;
use App\Modules\Versioning\Services\ManifestService;
use App\Modules\Versioning\Services\ReleaseService;
use App\Modules\Versioning\Services\VersioningService;
use Illuminate\Console\Command;

class VersioningRelease extends Command
{
    protected $signature = 'versioning:release
        {--release-version= : Numéro de version (ex: 1.1.0)}
        {--type=patch : Type d\'incrémentation si version non spécifiée (patch, minor, major)}
        {--notes= : Notes ou changelog de la release}
        {--env= : Environnement cible (local, recette, production)}
        {--preparer : Préparer directement la release après création}';

    protected $description = 'Crée et prépare une nouvelle release locale.';

    public function handle(
        ReleaseService $releaseService,
        VersioningService $versioning,
        ManifestService $manifestService
    ): int {
        $appActuelle = $versioning->versionApp();
        $dbRelease = $versioning->dbRelease();
        $dbSchema = $versioning->dbSchema();
        $buildCourant = $versioning->buildCourant();

        $version = $this->option('release-version');
        if (! $version) {
            $type = $this->option('type') ?: 'patch';
            $version = $versioning->incrementer($appActuelle, $type);
            $this->info("Version calculée automatiquement ({$type}) : {$version}");
        }

        if (! VersioningService::versionValide($version)) {
            $this->error("Format de version invalide : '{$version}'. Attendu : X.Y.Z");

            return self::FAILURE;
        }

        if (Release::where('version', $version)->exists()) {
            $this->error("La release {$version} existe déjà dans le système.");

            return self::FAILURE;
        }

        $notes = $this->option('notes') ?: "Release {$version} préparée via CLI";
        $env = $this->option('env') ?: config('versioning.environnement', 'local');

        $this->line("Préparation de la release <fg=cyan;options=bold>{$version}</> ({$env})...");

        try {
            $release = $releaseService->creer([
                'version' => $version,
                'environnement' => $env,
                'app_version' => $version,
                'db_release' => $dbRelease,
                'db_version' => $dbRelease,
                'db_schema' => $dbSchema,
                'dev_version' => "{$version}-dev.1",
                'prod_version' => $version,
                'build_number' => $buildCourant,
                'notes' => $notes,
            ]);

            $this->info("✔ Release {$release->version} créée en statut '{$release->statut_libelle}' (ID: {$release->id}).");

            if ($this->option('preparer')) {
                $releaseService->transiter($release, Release::STATUT_PREPAREE);
                $this->info("✔ Release {$release->version} passée en statut 'Préparée'.");

                // Génération anticipée du manifest
                $manifest = $manifestService->generer([
                    'version' => $release->version,
                    'app_version' => $release->app_version,
                    'db_release' => $release->db_release,
                    'db_version' => $release->db_version,
                    'db_schema' => $release->db_schema,
                    'build_number' => $release->build_number,
                    'notes' => $release->notes,
                ]);
                $manifestService->stocker($manifest, $release->version);
                $this->line('✔ Manifest généré et stocké.');
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Erreur lors de la création de la release : '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
