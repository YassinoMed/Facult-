<?php

namespace App\Services\Sauvegardes;

use App\Models\DatabaseBackup;
use App\Models\IntegrationLog;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\ParametreService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Restauration gardée (LOOP BACKUP-01 dette 4) : opération critique
 * verrouillée — checksum + restore test SUCCESS exigés, confirmation
 * exacte, pré-restore obligatoire AVANT (rollback), maintenance
 * pendant l'import, audit + notification dans tous les cas.
 * Permission `system.backups.restore` vérifiée au contrôleur.
 */
class RestaurationSauvegardeService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * @throws \RuntimeException (CHECKSUM_INVALID, RESTORE_TEST_MANQUANT,
     *                           CONFIRMATION_INVALIDE, PRE_RESTORE_ECHEC, NOT_SUPPORTED, IMPORT_ECHOUE)
     */
    public function restaurer(DatabaseBackup $backup, string $confirmation, ?int $auteurId = null): DatabaseBackup
    {
        $backup->refresh();

        if ($backup->database_name === null
            || $confirmation !== 'RESTORE '.$backup->database_name) {
            throw new \RuntimeException('CONFIRMATION_INVALIDE');
        }

        if (! is_file($backup->path)
            || ($backup->checksum !== null && hash_file('sha256', $backup->path) !== $backup->checksum)) {
            $this->tracer($backup, false, 'CHECKSUM_INVALID');

            throw new \RuntimeException('CHECKSUM_INVALID');
        }

        if ($backup->restore_test_status !== 'SUCCESS') {
            $this->tracer($backup, false, 'RESTORE_TEST_MANQUANT');

            throw new \RuntimeException('RESTORE_TEST_MANQUANT : testez la restauration avant tout restore production.');
        }

        $connexion = $backup->connexion ?: (string) config('database.default');
        $config = config("database.connections.{$connexion}");

        if (! is_array($config)) {
            throw new \RuntimeException('NOT_SUPPORTED');
        }

        // Backup pré-restore AVANT toute écriture : sans lui, STOP.
        $preRestore = $this->sauvegardePreRestore($backup, $connexion, $auteurId);

        if ($preRestore === null) {
            $this->tracer($backup, false, 'PRE_RESTORE_ECHEC');

            throw new \RuntimeException('PRE_RESTORE_ECHEC : restauration annulée, production intacte.');
        }

        $maintenanceActivee = false;

        try {
            Artisan::call('down');
            $maintenanceActivee = true;

            $this->importer($backup, $config);

            $this->tracer($backup, true, null);
            $this->notifier($backup, $auteurId, true);

            return $backup->fresh();
        } catch (\Throwable $e) {
            report($e);
            $this->tracer($backup, false, 'IMPORT_ECHOUE');
            $this->notifier($backup, $auteurId, false);

            throw new \RuntimeException('IMPORT_ECHOUE : restaurez le pré-restore '.$preRestore->filename.'.');
        } finally {
            if ($maintenanceActivee) {
                Artisan::call('up');
            }
        }
    }

    /**
     * Pré-restore via le moteur existant, renommé `pre-restore-*`
     * (manifest ajusté, checksum inchangé par renommage).
     */
    private function sauvegardePreRestore(DatabaseBackup $backup, string $connexion, ?int $auteurId = null): ?DatabaseBackup
    {
        $dossier = dirname($backup->path);
        $avant = $this->inventaire($dossier);

        if (Artisan::call('sauvegarde:creer', [
            '--dossier' => $dossier,
            '--connexion' => $connexion,
            '--conserver' => 14,
            '--sans-documents' => true,
        ]) !== 0) {
            return null;
        }

        $nouveaux = array_diff($this->inventaire($dossier), $avant);
        $manifests = array_values(array_filter(
            $nouveaux, fn ($f) => str_starts_with($f, 'manifest-') && str_ends_with($f, '.json')
        ));

        if ($manifests === []) {
            return null;
        }

        $cheminManifest = $dossier.'/'.$manifests[0];
        $donnees = json_decode((string) file_get_contents($cheminManifest), true);
        $fichier = basename((string) ($donnees['artefacts']['base']['fichier'] ?? ''));

        if ($fichier === '' || ! is_file($dossier.'/'.$fichier)) {
            return null;
        }

        $nouveauNom = 'pre-restore-'.$fichier;
        $nouveauManifest = $dossier.'/pre-restore-'.basename($cheminManifest);

        if (! @rename($dossier.'/'.$fichier, $dossier.'/'.$nouveauNom)
            || ! @rename($cheminManifest, $nouveauManifest)) {
            return null;
        }

        $donnees['artefacts']['base']['fichier'] = $nouveauNom;
        file_put_contents($nouveauManifest, json_encode($donnees, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return DatabaseBackup::create([
            'uuid' => (string) Str::uuid(),
            'filename' => $nouveauNom,
            'disk' => 'local',
            'path' => $dossier.'/'.$nouveauNom,
            'database_name' => $backup->database_name,
            'connexion' => $connexion,
            'size_bytes' => filesize($dossier.'/'.$nouveauNom),
            'checksum' => hash_file('sha256', $dossier.'/'.$nouveauNom),
            'type' => 'MANUAL',
            'status' => 'SUCCESS',
            'started_at' => now(),
            'completed_at' => now(),
            'created_by' => $auteurId,
        ]);
    }

    /**
     * @return array<int, string> noms de fichiers du dossier.
     */
    private function inventaire(string $dossier): array
    {
        return array_map(
            'basename',
            array_filter(glob($dossier.'/*') ?: [], 'is_file')
        );
    }

    private function importer(DatabaseBackup $backup, array $config): void
    {
        $driver = (string) ($config['driver'] ?? '');

        if ($driver === 'mysql') {
            $env = ['MYSQL_PWD' => (string) ($config['password'] ?? '')] + getenv();
            $import = new Process([
                'mysql',
                '-h', (string) ($config['host'] ?? '127.0.0.1'),
                '-P', (string) ($config['port'] ?? '3306'),
                '-u', (string) ($config['username'] ?? 'root'),
                (string) $backup->database_name,
            ], null, $env);
            $import->setTimeout(1200);
            $import->setInput((string) file_get_contents($backup->path));
            $import->run();

            if (! $import->isSuccessful()) {
                Log::warning('sauvegarde.restore_import_echec', ['backup_id' => $backup->id]);

                throw new \RuntimeException('IMPORT_ECHOUE');
            }

            return;
        }

        if ($driver === 'sqlite') {
            $cible = (string) ($config['database'] ?? '');

            if ($cible === '' || $cible === ':memory:') {
                throw new \RuntimeException('NOT_SUPPORTED');
            }

            $tmp = $cible.'.restore-tmp';

            if (! @copy($backup->path, $tmp)) {
                throw new \RuntimeException('IMPORT_ECHOUE');
            }

            if (! @rename($tmp, $cible)) {
                @unlink($tmp);

                throw new \RuntimeException('IMPORT_ECHOUE');
            }

            return;
        }

        throw new \RuntimeException('NOT_SUPPORTED');
    }

    private function tracer(DatabaseBackup $backup, bool $succes, ?string $code): void
    {
        IntegrationLog::tracer(
            'sauvegarde', 'sauvegarde',
            $succes ? 'backup.restore_succeeded' : 'backup.restore_failed',
            $succes ? 'completed' : 'failed',
            null, null, null, $code, null, $backup->uuid,
        );
    }

    private function notifier(DatabaseBackup $backup, ?int $auteurId, bool $succes): void
    {
        $auteur = $auteurId !== null ? User::find($auteurId) : null;

        if (! $auteur instanceof User) {
            return;
        }

        if (! $succes && ! app(ParametreService::class)->get('sauvegarde.notifier_echec', true)) {
            return;
        }

        $this->notifications->notifierUser(
            $auteur,
            $succes ? 'sauvegarde.restore_reussi' : 'sauvegarde.restore_echec',
            $succes ? 'Restauration database terminée' : 'Restauration database en échec',
            $succes
                ? "La base {$backup->database_name} a été restaurée depuis {$backup->filename}."
                : "La restauration depuis {$backup->filename} a échoué. Production intacte.",
            route('admin.system.backups.index')
        );
    }
}
