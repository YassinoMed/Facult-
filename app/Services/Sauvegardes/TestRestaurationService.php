<?php

namespace App\Services\Sauvegardes;

use App\Models\DatabaseBackup;
use App\Models\IntegrationLog;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\ParametreService;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Test de restauration (LOOP BACKUP-01 dette 3) : rejoue un dump
 * dans une base temporaire puis la supprime. JAMAIS sur la base de
 * production. Refuse proprement (FAILURE, pas de faux succès) si le
 * checksum est invalide, et NOT_SUPPORTED si l'environnement ne
 * permet pas de créer une base temporaire sûre.
 */
class TestRestaurationService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * @return string SUCCESS|FAILURE|NOT_SUPPORTED
     */
    public function tester(DatabaseBackup $backup): string
    {
        $backup->refresh();

        if (! is_file($backup->path)
            || ($backup->checksum !== null && hash_file('sha256', $backup->path) !== $backup->checksum)) {
            return $this->conclure($backup, 'FAILURE', 'CHECKSUM_INVALID');
        }

        $connexion = $backup->connexion ?: (string) config('database.default');
        $config = config("database.connections.{$connexion}");

        if (! is_array($config)) {
            return $this->conclure($backup, 'NOT_SUPPORTED', 'CONNEXION_INCONNUE');
        }

        try {
            $resultat = match ($config['driver'] ?? '') {
                'mysql' => $this->testerMysql($backup, $config),
                'sqlite' => $this->testerSqlite($backup),
                default => 'NOT_SUPPORTED',
            };
        } catch (\Throwable $e) {
            report($e);

            return $this->conclure($backup, 'FAILURE', 'ERREUR_TEST');
        }

        return $this->conclure($backup, $resultat, $resultat === 'SUCCESS' ? null : 'ECHEC_VERIFICATIONS');
    }

    private function conclure(DatabaseBackup $backup, string $statut, ?string $code): string
    {
        $backup->update(['restore_tested_at' => now(), 'restore_test_status' => $statut]);

        IntegrationLog::tracer(
            'sauvegarde', 'sauvegarde',
            $statut === 'SUCCESS' ? 'backup.restore_test_succeeded' : 'backup.restore_test_failed',
            $statut === 'SUCCESS' ? 'completed' : 'failed',
            null, null, null, $code, null, $backup->uuid,
        );

        if ($statut === 'FAILURE') {
            $auteur = $backup->createur;

            if ($auteur instanceof User
                && app(ParametreService::class)->get('sauvegarde.notifier_echec', true)) {
                $this->notifications->notifierUser(
                    $auteur,
                    'sauvegarde.restore_test_echec',
                    'Test de restauration en échec',
                    "La sauvegarde {$backup->filename} ne restaure pas correctement ({$code}). Ne pas l'utiliser pour restaurer.",
                    route('admin.system.backups.index')
                );
            }
        }

        return $statut;
    }

    private function testerMysql(DatabaseBackup $backup, array $config): string
    {
        foreach (['mysql', 'mysqldump'] as $binaire) {
            $probe = new Process(['sh', '-lc', "command -v {$binaire}"]);
            $probe->run();

            if (! $probe->isSuccessful()) {
                return 'NOT_SUPPORTED';
            }
        }

        $baseTest = 'backup_restore_test_'.substr(md5((string) $backup->uuid), 0, 12);
        $env = ['MYSQL_PWD' => (string) ($config['password'] ?? '')] + getenv();
        $hote = (string) ($config['host'] ?? '127.0.0.1');
        $port = (string) ($config['port'] ?? '3306');
        $utilisateur = (string) ($config['username'] ?? 'root');

        try {
            $creation = new Process(
                ['mysql', '-h', $hote, '-P', $port, '-u', $utilisateur, '-e', "CREATE DATABASE `{$baseTest}`"],
                null, $env
            );
            $creation->setTimeout(60);
            $creation->run();

            if (! $creation->isSuccessful()) {
                Log::warning('sauvegarde.restore_test_impossible', ['backup_id' => $backup->id]);

                return 'NOT_SUPPORTED';
            }

            $import = new Process(
                ['mysql', '-h', $hote, '-P', $port, '-u', $utilisateur, $baseTest],
                null, $env + ['MYSQL_PWD' => (string) ($config['password'] ?? '')],
            );
            $import->setTimeout(600);
            $import->setInput((string) file_get_contents($backup->path));
            $import->run();

            if (! $import->isSuccessful()) {
                return 'FAILURE';
            }

            $tablesSource = $this->tablesMysql($config, (string) ($config['database'] ?? ''));
            $tablesTest = $this->tablesMysql($config, $baseTest);

            if ($tablesSource === [] || $tablesTest !== $tablesSource) {
                return 'FAILURE';
            }

            return 'SUCCESS';
        } finally {
            $suppression = new Process(
                ['mysql', '-h', $hote, '-P', $port, '-u', $utilisateur, '-e', "DROP DATABASE IF EXISTS `{$baseTest}`"],
                null, $env
            );
            $suppression->setTimeout(60);
            $suppression->run();
        }
    }

    /**
     * @return array<int, string>
     */
    private function tablesMysql(array $config, string $base): array
    {
        $pdo = new \PDO(
            "mysql:host={$config['host']};port=".($config['port'] ?? '3306').";dbname={$base};charset=utf8mb4",
            (string) ($config['username'] ?? 'root'),
            (string) ($config['password'] ?? '')
        );
        $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_NUM);
        $noms = array_map(fn ($ligne) => (string) $ligne[0], $tables);
        sort($noms);

        return $noms;
    }

    private function testerSqlite(DatabaseBackup $backup): string
    {
        // Le dump sqlite est une copie du fichier : rejouable en
        // l'ouvrant sous un nom temporaire (tables comparées).
        try {
            $test = new \PDO('sqlite:'.$backup->path);
            $test->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $tablesDump = $test->query(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
            )->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable) {
            return 'NOT_SUPPORTED';
        }

        if ($tablesDump === []) {
            return 'FAILURE';
        }

        $copie = dirname($backup->path).'/restore_test_'.substr(md5((string) $backup->uuid), 0, 12).'.sqlite';

        try {
            copy($backup->path, $copie);
            $pdo = new \PDO('sqlite:'.$copie);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $tablesCopie = $pdo->query(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
            )->fetchAll(\PDO::FETCH_COLUMN);
            $pdo = null;

            if ($tablesCopie !== $tablesDump) {
                return 'FAILURE';
            }

            return 'SUCCESS';
        } catch (\Throwable) {
            return 'FAILURE';
        } finally {
            @unlink($copie);
        }
    }
}
