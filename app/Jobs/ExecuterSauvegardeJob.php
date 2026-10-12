<?php

namespace App\Jobs;

use App\Models\DatabaseBackup;
use App\Models\IntegrationLog;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\ParametreService;
use App\Services\Sauvegardes\RegistreSauvegardesService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Exécution d'une sauvegarde database (LOOP BACKUP-01) : ORCHESTRATION
 * UNIQUEMENT. Le dump reste dans `sauvegarde:creer` (jamais dupliqué) ;
 * ce Job gère verrou, registre, vérification, audit et notification.
 * Idempotent : un lot RUNNING existant bloque tout doublon (409).
 */
class ExecuterSauvegardeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200;

    public int $tries = 1;

    public function __construct(public readonly int $backupId, public readonly ?string $connexion = null, public readonly ?bool $chiffrer = null)
    {
        $this->onQueue('default');
    }

    private function ligne(): DatabaseBackup
    {
        $requete = new DatabaseBackup;

        if ($this->connexion !== null) {
            $requete = $requete->on($this->connexion);
        }

        return $requete->findOrFail($this->backupId);
    }

    public function handle(NotificationService $notifications, RegistreSauvegardesService $registre): void
    {
        $backup = $this->ligne();

        if ($backup->status !== 'RUNNING') {
            return;
        }

        $debut = microtime(true);
        $verrou = null;

        try {
            // Contrat `path` = fichier : un dossier ici signifierait
            // écrire hors du dossier configuré (fuite constatée en test).
            if (is_dir($backup->path)) {
                throw new \RuntimeException('CHEMIN_INVALIDE : path doit désigner un fichier.');
            }

            $verrou = Cache::lock('sauvegarde:execution', 3600);

            if (! $verrou->acquire()) {
                Log::warning('sauvegarde.concurrence_refusee', ['backup_id' => $backup->id]);

                return;
            }

            $code = Artisan::call('sauvegarde:creer', array_filter([
                '--dossier' => dirname($backup->path),
                '--connexion' => $backup->connexion,
                '--conserver' => max(1, (int) app(ParametreService::class)->get('sauvegarde.conserver', 14)),
                '--conserver-jours' => max(1, (int) app(ParametreService::class)->get('sauvegarde.plan.retention_jours', 14)),
                '--chiffrer' => $this->chiffrer === true,
                '--verifier' => false,
            ], fn ($valeur) => $valeur !== null && $valeur !== false));

            if ($code !== 0) {
                throw new \RuntimeException('sauvegarde:creer a échoué (voir logs commande).');
            }

            $cheminManifest = $registre->dernierManifest(dirname($backup->path));
            $donnees = $cheminManifest !== null
                ? json_decode((string) file_get_contents($cheminManifest), true)
                : null;
            $artefact = is_array($donnees) ? ($donnees['artefacts']['base'] ?? null) : null;

            if (! is_array($artefact) || empty($artefact['fichier'])) {
                throw new \RuntimeException('Manifest illisible après sauvegarde.');
            }

            $chemin = dirname($backup->path).'/'.basename((string) $artefact['fichier']);

            $backup->update([
                'filename' => basename((string) $artefact['fichier']),
                'path' => $chemin,
                'size_bytes' => is_file($chemin) ? filesize($chemin) : 0,
                'checksum' => $artefact['sha256'] ?? null,
                'status' => 'SUCCESS',
                'completed_at' => now(),
                'duration_ms' => (int) ((microtime(true) - $debut) * 1000),
            ]);

            // Vérification immédiate (checksum manifest) : jamais de
            // SUCCESS non vérifié quand le moteur le permet.
            $codeVerification = Artisan::call('sauvegarde:creer', [
                '--dossier' => dirname($backup->path),
                '--verifier' => true,
            ]);

            $backup->update([
                'verified_at' => $codeVerification === 0 ? now() : null,
                'status' => $codeVerification === 0 ? 'SUCCESS' : 'VERIFICATION_FAILED',
            ]);

            IntegrationLog::tracer(
                'sauvegarde', 'sauvegarde', 'backup.created', 'completed',
                null, null, (int) ((microtime(true) - $debut) * 1000), null, null, $backup->uuid,
            );
        } catch (\Throwable $e) {
            report($e);

            $backup->update([
                'status' => 'FAILED',
                'completed_at' => now(),
                'duration_ms' => (int) ((microtime(true) - $debut) * 1000),
                'error_code' => mb_substr($e->getMessage(), 0, 100),
            ]);

            IntegrationLog::tracer(
                'sauvegarde', 'sauvegarde', 'backup.failed', 'failed',
                null, null, null, 'BACKUP_FAILED', null, $backup->uuid,
            );

            $this->notifierEchec($notifications, $backup);
            throw $e;
        } finally {
            $verrou?->release();
        }
    }

    public function failed(\Throwable $e): void
    {
        $backup = $this->connexion !== null
            ? DatabaseBackup::on($this->connexion)->find($this->backupId)
            : DatabaseBackup::find($this->backupId);

        if ($backup !== null && in_array($backup->status, ['RUNNING'], true)) {
            $backup->update([
                'status' => 'FAILED',
                'completed_at' => now(),
                'error_code' => mb_substr($e->getMessage(), 0, 100),
            ]);
        }
    }

    private function notifierEchec(NotificationService $notifications, DatabaseBackup $backup): void
    {
        if (! app(ParametreService::class)->get('sauvegarde.notifier_echec', true)) {
            return;
        }

        $auteur = $backup->createur;

        if (! $auteur instanceof User) {
            return;
        }

        // Échec toujours notifié ; succès silencieux (évite le spam,
        // dashboard faisant foi — configurable dette 5).
        $notifications->notifierUser(
            $auteur,
            'sauvegarde.echec',
            'Sauvegarde database en échec',
            "La sauvegarde {$backup->filename} a échoué ({$backup->error_code}).",
            route('admin.system.backups.index')
        );
    }
}
