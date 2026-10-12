<?php

namespace App\Console\Commands;

use App\Modules\Versioning\Models\Release;
use App\Modules\Versioning\Services\CompatibilityService;
use App\Modules\Versioning\Services\DeploymentService;
use Illuminate\Console\Command;

class VersioningDeploy extends Command
{
    protected $signature = 'versioning:deploy
        {version : Version de la release à déployer}
        {--force : Forcer le déploiement même en cas d\'avertissement de compatibilité}
        {--sans-backup : Ne pas créer de sauvegarde préalable (déconseillé)}';

    protected $description = 'Déploie une release préparée ou approuvée sur l\'environnement local.';

    public function handle(
        DeploymentService $deploymentService,
        CompatibilityService $compatibility
    ): int {
        $version = $this->argument('version');
        $release = Release::where('version', $version)->first();

        if (! $release) {
            $this->error("Release {$version} introuvable.");

            return self::FAILURE;
        }

        $this->info('══════════════════════════════════════════════════════');
        $this->info("           DÉPLOIEMENT LOCAL : RELEASE {$version}       ");
        $this->info('══════════════════════════════════════════════════════');

        // 1. Contrôle préalable
        $verif = $compatibility->verifierPreDeploiement($release);

        if (! $verif['autorise']) {
            $this->error('✖ DÉPLOIEMENT BLOQUÉ :');
            foreach ($verif['bloquants'] as $b) {
                $this->line("  • {$b}");
            }

            if (! $this->option('force')) {
                $this->warn("Pour outrepasser (à vos risques), utilisez l'option --force.");

                return self::FAILURE;
            }

            $this->warn('⚠ Option --force active : passage outre les blocages.');
        }

        if (count($verif['avertissements']) > 0) {
            $this->warn('Avertissements :');
            foreach ($verif['avertissements'] as $a) {
                $this->line("  • {$a}");
            }
        }

        // Confirmation interactive
        if ($this->input->isInteractive() && ! $this->confirm("Confirmez-vous le déploiement de la release {$version} ?")) {
            $this->comment("Déploiement annulé par l'utilisateur.");

            return self::SUCCESS;
        }

        $this->line('Lancement du déploiement...');

        try {
            $deploiement = $deploymentService->deployer($release, null, [
                'sans_backup' => (bool) $this->option('sans-backup'),
                'forcer' => (bool) $this->option('force'),
            ]);

            $this->newLine();
            $this->info("✔ DÉPLOIEMENT RÉUSSI ! (ID: {$deploiement->id})");
            $this->line(" • Durée : {$deploiement->duree_secondes}s");
            $this->line(" • Environnement : {$deploiement->environnement}");
            if ($deploiement->backup_path) {
                $this->line(" • Sauvegarde : {$deploiement->backup_path}");
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('✖ ÉCHEC DU DÉPLOIEMENT : '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
