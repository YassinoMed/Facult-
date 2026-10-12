<?php

namespace App\Jobs;

use App\Models\DatabaseBackup;
use App\Models\User;
use App\Services\BackupSchedulerService;
use App\Services\BackupService;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Job asynchrone pour l'exécution d'une sauvegarde SQL complète.
 *
 * Caractéristiques :
 * - Empêche l'exécution concurrente via verrou distribué (Cache::lock).
 * - Gère l'idempotence et la détection d'une sauvegarde en cours.
 * - Délègue l'exécution à BackupService.
 * - Notifie les super-administrateurs via NotificationService selon la configuration.
 */
class CreateSqlBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Nombre de tentatives autorisées.
     */
    public int $tries = 1;

    /**
     * Timeout en secondes.
     */
    public int $timeout = 900;

    public function __construct(
        public readonly string $type = 'SCHEDULED',
        public readonly ?int $userId = null,
        public readonly ?string $connexion = null,
        public readonly ?string $dossierCible = null,
        public readonly ?bool $compresser = null
    ) {
        $this->onQueue('default');
    }

    public function handle(
        BackupService $backupService,
        BackupSchedulerService $scheduler,
        NotificationService $notificationService
    ): ?DatabaseBackup {
        $lockKey = 'sauvegarde:execution_verrou';
        $lock = Cache::lock($lockKey, 1200);

        // Si une sauvegarde est déjà en cours d'exécution dans le système
        if (! $lock->get()) {
            Log::warning('sauvegarde.job_ignore_verrou_actif', [
                'type' => $this->type,
                'user_id' => $this->userId,
            ]);

            return null;
        }

        try {
            // Vérification additionnelle au niveau de la table
            if (DatabaseBackup::where('status', 'RUNNING')->exists()) {
                Log::warning('sauvegarde.job_ignore_backup_running_existant');

                return null;
            }

            $backup = $backupService->create(
                $this->userId,
                $this->connexion,
                $this->dossierCible,
                $this->type,
                $this->compresser
            );

            // Notification optionnelle en cas de succès
            if ($scheduler->alerteSucces()) {
                $this->notifierDestinataires(
                    $notificationService,
                    'sauvegarde.succes',
                    'Sauvegarde SQL réussie',
                    "La sauvegarde {$backup->filename} ({$backup->taille_lisible}) a été créée et vérifiée avec succès."
                );
            }

            return $backup;
        } catch (\Throwable $e) {
            Log::error('sauvegarde.job_echec', [
                'type' => $this->type,
                'user_id' => $this->userId,
                'message' => $e->getMessage(),
            ]);

            // Notification en cas d'échec
            if ($scheduler->alerteEchec()) {
                $this->notifierDestinataires(
                    $notificationService,
                    'sauvegarde.echec',
                    'Échec de la sauvegarde SQL',
                    "La tentative de sauvegarde ({$this->type}) a échoué : {$e->getMessage()}"
                );
            }

            throw $e;
        } finally {
            $lock->release();
        }
    }

    /**
     * Notifie le déclencheur et les super-administrateurs de l'événement.
     */
    protected function notifierDestinataires(
        NotificationService $notificationService,
        string $type,
        string $titre,
        string $message
    ): void {
        $url = route('admin.system.backups.index');

        if ($this->userId !== null) {
            $auteur = User::find($this->userId);
            if ($auteur instanceof User) {
                $notificationService->notifierUser($auteur, $type, $titre, $message, $url);

                return;
            }
        }

        $superAdmins = User::where('role', 'super_admin')->get();
        foreach ($superAdmins as $admin) {
            $notificationService->notifierUser($admin, $type, $titre, $message, $url);
        }
    }
}
