<?php

namespace App\Console\Commands;

use App\Modules\Versioning\Models\Release;
use App\Modules\Versioning\Services\DeploymentService;
use Illuminate\Console\Command;

class VersioningRollback extends Command
{
    protected $signature = 'versioning:rollback
        {version? : Version de la release cible vers laquelle revenir (défaut : précédente)}
        {--avec-db : Exécuter le rollback des migrations de base de données}
        {--force : Ne pas demander de confirmation interactive}';

    protected $description = 'Exécute un retour arrière (rollback) contrôlé vers une release antérieure.';

    public function handle(DeploymentService $deploymentService): int
    {
        $version = $this->argument('version');

        if ($version) {
            $releaseCible = Release::where('version', $version)->first();
        } else {
            // Prendre la release précédente de la release actuellement déployée
            $actuelle = Release::where('statut', Release::STATUT_DEPLOYEE)->latest('deployed_at')->first();
            $releaseCible = $actuelle?->releasePrecedente
                ?? Release::where('statut', '!=', Release::STATUT_DEPLOYEE)->latest('id')->first();
        }

        if (! $releaseCible) {
            $this->error('Release cible introuvable pour le rollback.');

            return self::FAILURE;
        }

        $this->info('══════════════════════════════════════════════════════');
        $this->info('             PLAN DE ROLLBACK SYSTÈME                ');
        $this->info('══════════════════════════════════════════════════════');

        try {
            $plan = $deploymentService->planifierRollback($releaseCible);

            $this->line("Release actuelle : <fg=red;options=bold>{$plan['release_actuelle']->version}</>");
            $this->line("Release cible    : <fg=green;options=bold>{$plan['release_cible']->version}</>");
            $this->newLine();

            $this->line("<fg=cyan;options=bold>Plan d'exécution :</>");
            foreach ($plan['plan'] as $etape) {
                $this->line(" • {$etape}");
            }

            if (count($plan['avertissements']) > 0) {
                $this->newLine();
                $this->warn('Avertissements :');
                foreach ($plan['avertissements'] as $a) {
                    $this->line(" ⚠ {$a}");
                }
            }

            if (! $plan['possible']) {
                $this->newLine();
                $this->error('✖ ROLLBACK IMPOSSIBLE :');
                foreach ($plan['bloquants'] as $b) {
                    $this->line(" • {$b}");
                }

                return self::FAILURE;
            }

            if (! $this->option('force') && $this->input->isInteractive()) {
                if (! $this->confirm("Confirmez-vous le ROLLBACK vers la release {$releaseCible->version} ?")) {
                    $this->comment('Opération de rollback annulée.');

                    return self::SUCCESS;
                }
            }

            $this->line('Exécution du rollback...');

            $deploiement = $deploymentService->rollback($releaseCible, null, [
                'rollback_db' => (bool) $this->option('avec-db'),
            ]);

            $this->newLine();
            $this->info("✔ ROLLBACK RÉUSSI ! (ID: {$deploiement->id})");
            $this->line(" • Système restauré à la version {$releaseCible->version}");
            $this->line(" • Durée : {$deploiement->duree_secondes}s");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('✖ ÉCHEC DU ROLLBACK : '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
