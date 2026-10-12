<?php

namespace App\Console\Commands;

use App\Modules\Versioning\Models\Release;
use App\Modules\Versioning\Services\ManifestService;
use App\Modules\Versioning\Services\VersioningService;
use Illuminate\Console\Command;

class VersioningManifest extends Command
{
    protected $signature = 'versioning:manifest
        {version? : Numéro de version de la release (par défaut: release déployée ou app)}
        {--generer : Forcer la génération d\'un nouveau manifest}
        {--verifier : Vérifier l\'intégrité cryptographique (SHA-256) du manifest existant}
        {--afficher : Afficher le contenu JSON complet}';

    protected $description = 'Génère, stocke ou vérifie le manifest d\'une release.';

    public function handle(ManifestService $manifestService, VersioningService $versioning): int
    {
        $version = $this->argument('version');

        if (! $version) {
            $deployee = Release::where('statut', Release::STATUT_DEPLOYEE)->latest('deployed_at')->first();
            $version = $deployee?->version ?? $versioning->versionApp();
        }

        $this->line("Manifest pour la release <fg=cyan;options=bold>{$version}</> :");

        // Option 1 : Vérifier intégrité
        if ($this->option('verifier')) {
            $manifest = $manifestService->charger($version);
            if (! $manifest) {
                $this->error("Aucun fichier manifest trouvé pour la version {$version}.");

                return self::FAILURE;
            }

            $valide = $manifestService->verifierIntegrite($manifest);
            if ($valide) {
                $this->info('✔ Intégrité vérifiée : le hash SHA-256 du manifest correspond parfaitement.');
                $this->line("  Hash : <fg=green>{$manifest['manifest_hash']}</>");

                return self::SUCCESS;
            } else {
                $this->error('✖ ALERTE : Le manifest a été altéré ou son hash est invalide !');

                return self::FAILURE;
            }
        }

        // Option 2 : Générer ou Régénérer
        if ($this->option('generer')) {
            $release = Release::where('version', $version)->first();
            $manifest = $manifestService->generer([
                'version' => $version,
                'app_version' => $release?->app_version ?? $version,
                'db_version' => $release?->db_version ?? $versioning->versionDb(),
                'build_number' => $release?->build_number ?? $versioning->buildCourant(),
                'notes' => $release?->notes,
            ]);

            $chemin = $manifestService->stocker($manifest, $version);
            $this->info('✔ Manifest généré avec succès.');
            $this->line("  Emplacement : {$chemin}");
            $this->line("  Hash : <fg=green>{$manifest['manifest_hash']}</>");
        } else {
            $manifest = $manifestService->charger($version);
            if (! $manifest) {
                $this->warn("Aucun manifest stocké pour {$version}. Utilisez --generer pour le créer.");

                return self::FAILURE;
            }
        }

        if ($this->option('afficher') && isset($manifest)) {
            $this->line(json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }
}
