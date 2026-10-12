<?php

namespace App\Console\Commands;

use App\Jobs\ExecuterSauvegardeJob;
use App\Models\DatabaseBackup;
use App\Services\BackupService;
use App\Services\Sauvegardes\BackupSchedulerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Évaluateur du planning (`sauvegarde.plan.*`, toutes les 15 minutes) :
 * décide (`BackupSchedulerService::estDue`), protège (verrou + ligne
 * RUNNING + reprise des RUNNING périmés) puis délègue l'exécution lourde
 * au job (`ExecuterSauvegardeJob`). Idempotent : deux évaluations
 * concurrentes ne créent qu'une seule sauvegarde. Ne diagnostique une
 * panne MySQL que si la base répond (sinon : log + sortie explicite,
 * jamais de fausse écriture).
 */
class EvaluerSauvegardes extends Command
{
    protected $signature = 'sauvegarde:evaluer';

    protected $description = 'Évalue le planning de sauvegarde et déclenche une sauvegarde si échue.';

    /** RUNNING plus vieux que ce délai = worker mort (reprise explicite). */
    public const STALE_SECONDES = 7200;

    public function handle(BackupSchedulerService $planificateur): int
    {
        $plan = $planificateur->plan();

        if (! $plan['actif']) {
            $this->info('Planification désactivée.');

            return self::SUCCESS;
        }

        try {
            $du = $planificateur->estDue();
        } catch (\Throwable $e) {
            // Base injoignable : diagnostic minimal, jamais de fausse écriture.
            Log::warning('sauvegarde.evaluation_impossible', [
                'message' => mb_substr($e->getMessage(), 0, 200),
            ]);
            $this->error('Base injoignable : évaluation impossible.');

            return self::FAILURE;
        }

        if (! $du) {
            $this->info('Rien à sauvegarder.');

            return self::SUCCESS;
        }

        // Reprise des RUNNING périmés (worker mort) avant tout.
        DatabaseBackup::where('status', 'RUNNING')
            ->where('started_at', '<', now()->subSeconds(self::STALE_SECONDES))
            ->update([
                'status' => 'FAILED',
                'completed_at' => now(),
                'error_code' => 'STALE_RUNNING : worker sans signe de vie.',
            ]);

        if (DatabaseBackup::where('status', 'RUNNING')->exists()) {
            $this->info('Sauvegarde déjà en cours : doublon évité.');

            return self::SUCCESS;
        }

        $verrou = Cache::lock('sauvegarde:planification', 60);

        if (! $verrou->acquire()) {
            $this->info('Évaluation concurrente : doublon évité.');

            return self::SUCCESS;
        }

        try {
            // Re-vérification sous verrou (deux évaluateurs simultanés).
            if (DatabaseBackup::where('status', 'RUNNING')->exists()) {
                $this->info('Sauvegarde déjà en cours : doublon évité.');

                return self::SUCCESS;
            }

            try {
                // Destination résolue via le registre (clés explicites,
                // validation + repli documentés dans BackupService).
                $dossier = app(BackupService::class)->dossierBackup($plan['destination']);

                if ($plan['destination'] !== app(BackupSchedulerService::class)->stockageDestination()) {
                    Log::warning('sauvegarde.destination_inconnue', ['destination' => $plan['destination']]);
                }
            } catch (\Throwable $e) {
                Log::warning('sauvegarde.destination_invalide', ['message' => mb_substr($e->getMessage(), 0, 200)]);

                return self::FAILURE;
            }

            $backup = DatabaseBackup::create([
                'uuid' => (string) Str::uuid(),
                'filename' => 'planifie-'.now()->format('Ymd-His').'.sql',
                'disk' => 'local',
                'path' => rtrim((string) $dossier, '/').'/planifie-'.now()->format('Ymd-His').'.sql',
                'database_name' => config('database.connections.'.config('database.default').'.database'),
                'connexion' => config('database.default'),
                'type' => 'SCHEDULED',
                'status' => 'RUNNING',
                'started_at' => now(),
            ]);

            ExecuterSauvegardeJob::dispatch($backup->id, null, $plan['chiffrer']);

            $this->info("Sauvegarde planifiée lancée : {$backup->uuid}.");

            return self::SUCCESS;
        } finally {
            $verrou->release();
        }
    }
}
