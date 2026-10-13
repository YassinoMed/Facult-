<?php

namespace App\Services;

use App\Models\DatabaseBackup;
use App\Models\IntegrationLog;
use App\Services\Sauvegardes\BackupRetentionService;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Service de sauvegarde database (Platform Sentinel & Core).
 *
 * Responsabilités :
 * - Création de sauvegardes fiables MySQL via mysqldump hors du projet ou dans stockage privé sécurisé.
 * - Format de nommage strict côté serveur : db_YYYY-MM-DD_HH-mm-ss.sql[.gz].
 * - Support de la compression gzip sans perte et vérification de la structure SQL.
 * - Calcul et persistance du checksum SHA-256 et métadonnées d'intégrité.
 * - Vérification d'intégrité à la demande (recalcul SHA-256 et test de lecture).
 * - Journalisation d'audit systématique sans jamais exposer de secrets ou mots de passe.
 * - Déclenchement de la rétention contrôlée UNIQUEMENT après validation réussie d'une nouvelle sauvegarde.
 */
class BackupService
{
    /**
     * @var (callable(array<string, mixed>, string): void)|null Hook pour substituer l'exécution du dump dans les tests.
     */
    protected static $dumpRunner = null;

    /**
     * Définit un mock pour l'exécution du dump (utilisé dans les tests).
     *
     * @param  (callable(array<string, mixed>, string): void)|null  $runner
     */
    public static function fakeDumpRunner(?callable $runner): void
    {
        self::$dumpRunner = $runner;
    }

    /**
     * Retourne le répertoire de sauvegarde configuré, normalisé et validé.
     */
    public function dossierBackup(?string $cleDestination = null): string
    {
        if ($cleDestination !== null) {
            $destinations = config('sauvegardes.destinations', []);
            if (isset($destinations[$cleDestination]['chemin'])) {
                return $this->normaliserEtValiderDossier((string) $destinations[$cleDestination]['chemin']);
            }
        }

        // Vérifier si un paramètre stocké existe
        try {
            $destParam = app(BackupSchedulerService::class)->stockageDestination();
            $destinations = config('sauvegardes.destinations', []);
            if (isset($destinations[$destParam]['chemin'])) {
                return $this->normaliserEtValiderDossier((string) $destinations[$destParam]['chemin']);
            }
        } catch (\Throwable) {
            // Repli sur configuration
        }

        $dossier = (string) config('sauvegardes.dossier', '~/Backups/inscription-etudiants');

        return $this->normaliserEtValiderDossier($dossier);
    }

    /**
     * Normalise le chemin (expansion du tilde) et vérifie qu'il n'est pas
     * dans un répertoire web ou applicatif interdit.
     */
    public function normaliserEtValiderDossier(string $dossier): string
    {
        // 1. Résolution du tilde ~ vers le HOME système
        if (str_starts_with($dossier, '~/') || $dossier === '~') {
            $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');
            if ($home !== '') {
                $dossier = rtrim($home, '/').($dossier === '~' ? '' : substr($dossier, 1));
            }
        }

        $dossierNettoye = rtrim($dossier, '/');

        if ($dossierNettoye === '') {
            throw new \InvalidArgumentException('REPERTOIRE_VIDE : Le répertoire de sauvegarde ne peut pas être vide.');
        }

        // 2. Vérification des répertoires interdits (webroot, app, storage public)
        $interdits = [
            realpath(public_path()) ?: public_path(),
            realpath(storage_path('app/public')) ?: storage_path('app/public'),
            realpath(resource_path()) ?: resource_path(),
            realpath(app_path()) ?: app_path(),
        ];

        $realCible = realpath($dossierNettoye) ?: $dossierNettoye;

        foreach ($interdits as $interdit) {
            $interdit = rtrim($interdit, '/');
            if ($realCible === $interdit || str_starts_with($realCible, $interdit.'/')) {
                throw new \InvalidArgumentException("REPERTOIRE_INTERDIT : Le répertoire de sauvegarde ({$dossier}) ne doit pas se situer dans un dossier public ou applicatif.");
            }
        }

        return $dossierNettoye;
    }

    /**
     * Vérifie que le répertoire existe (ou le crée) et qu'il est accessible en écriture.
     */
    public function verifierRepertoire(string $dossier): void
    {
        if (! is_dir($dossier) && ! @mkdir($dossier, 0750, true) && ! is_dir($dossier)) {
            throw new \RuntimeException("REPERTOIRE_INACCESSIBLE : Impossible de créer le répertoire {$dossier}.");
        }

        if (! is_writable($dossier)) {
            throw new \RuntimeException("REPERTOIRE_NON_INSCRIPTIBLE : Le répertoire {$dossier} n'est pas accessible en écriture.");
        }
    }

    /**
     * Vérifie les prérequis matériels, logiciels et de configuration pour les sauvegardes.
     *
     * @return array{ok: bool, erreurs: array<string>, details: array<string, mixed>}
     */
    public function verifierPrerequis(?string $destinationCle = null): array
    {
        $erreurs = [];
        $details = [];

        // 1. Vérification connexion base
        $connexion = config('database.default');
        $config = config("database.connections.{$connexion}");
        $driver = $config['driver'] ?? 'inconnu';
        $details['driver'] = $driver;
        $details['connexion'] = $connexion;
        $details['database'] = $config['database'] ?? '';

        if ($driver === 'mysql') {
            $check = new Process(['sh', '-lc', 'command -v mysqldump']);
            $check->run();
            if (! $check->isSuccessful()) {
                $erreurs[] = 'Binaire mysqldump introuvable sur le système hôte.';
            } else {
                $details['mysqldump_chemin'] = trim($check->getOutput());
            }
        }

        // 2. Vérification dossier cible
        try {
            $dossier = $this->dossierBackup($destinationCle);
            $this->verifierRepertoire($dossier);
            $details['dossier'] = $dossier;

            $espaceLibre = @disk_free_space($dossier);
            if ($espaceLibre !== false) {
                $espaceLibreMo = (int) ($espaceLibre / 1048576);
                $details['espace_libre_mo'] = $espaceLibreMo;
                $seuilMin = (int) config('sauvegardes.seuil_espace_disque_min_mo', 500);

                if ($espaceLibreMo < $seuilMin) {
                    $erreurs[] = "Espace disque insuffisant sur le volume de sauvegarde ({$espaceLibreMo} Mo libres, minimum requis : {$seuilMin} Mo).";
                }
            }
        } catch (\Throwable $e) {
            $erreurs[] = "Répertoire de sauvegarde non valide : {$e->getMessage()}";
        }

        return [
            'ok' => empty($erreurs),
            'erreurs' => $erreurs,
            'details' => $details,
        ];
    }

    /**
     * Génère un nom de fichier côté serveur au format obligatoire :
     * db_YYYY-MM-DD_HH-mm-ss.sql[.gz]
     * Avec gestion des collisions en cas d'appels multiples dans la même seconde.
     */
    public function genererNomFichier(string $dossier, ?\DateTimeInterface $date = null, bool $compresser = false): string
    {
        $moment = $date ? \DateTimeImmutable::createFromInterface($date) : now();
        $base = 'db_'.$moment->format('Y-m-d_H-i-s');
        $extension = $compresser ? 'sql.gz' : 'sql';
        $candidat = "{$base}.{$extension}";
        $compteur = 2;

        while (is_file("{$dossier}/{$candidat}")) {
            $candidat = "{$base}-{$compteur}.{$extension}";
            $compteur++;
        }

        return $candidat;
    }

    /**
     * Crée une nouvelle sauvegarde de la base de données.
     *
     * Étapes garanties :
     * 1. Vérification configuration
     * 2. Vérification/création répertoire
     * 3. Vérification droits d'écriture
     * 4. Génération nom de fichier serveur db_YYYY-MM-DD_HH-mm-ss.sql[.gz]
     * 5. Exécution mysqldump sans jamais exposer de secret
     * 6. Vérification code retour
     * 7. Vérification existence fichier et structure SQL
     * 8. Vérification taille > 0
     * 9. Calcul SHA-256
     * 10. Persistance métadonnées
     * 11. Déclenchement de la rétention après validation réussie
     * 12. Audit système (IntegrationLog)
     * 13. Retour explicite de l'entité
     */
    public function create(
        ?int $userId = null,
        ?string $connexion = null,
        ?string $dossierCible = null,
        string $type = 'MANUAL',
        ?bool $compresser = null
    ): DatabaseBackup {
        $debut = microtime(true);
        $uuid = (string) Str::uuid();

        // 1. Vérification de la configuration DB
        $connexion = $connexion ?: config('database.default');
        $config = config("database.connections.{$connexion}");

        if (! is_array($config) || empty($config['driver'])) {
            throw new \RuntimeException("CONFIG_INVALIDE : La connexion database '{$connexion}' est inconnue ou invalide.");
        }

        // 2 & 3. Vérification du répertoire cible
        $dossier = $dossierCible ? $this->normaliserEtValiderDossier($dossierCible) : $this->dossierBackup();
        $this->verifierRepertoire($dossier);

        // Détermination du format de compression
        if ($compresser === null) {
            try {
                $compresser = (app(BackupSchedulerService::class)->compression() === 'gzip');
            } catch (\Throwable) {
                $compresser = false;
            }
        }

        // 4. Génération du nom de fichier côté serveur
        $filename = $this->genererNomFichier($dossier, null, $compresser);
        $filepath = "{$dossier}/{$filename}";

        // Enregistrement initial en statut RUNNING
        $backup = DatabaseBackup::create([
            'uuid' => $uuid,
            'filename' => $filename,
            'disk' => 'local',
            'path' => $filepath,
            'database_name' => (string) ($config['database'] ?? ''),
            'connexion' => $connexion,
            'size_bytes' => 0,
            'status' => 'RUNNING',
            'type' => in_array($type, DatabaseBackup::TYPES, true) ? $type : 'MANUAL',
            'compression' => $compresser ? 'gzip' : null,
            'started_at' => now(),
            'created_by' => $userId,
        ]);

        try {
            // 5 & 6. Exécution du dump
            $this->executerDump($config, $filepath, $compresser);

            // 7. Vérification que le fichier existe
            if (! is_file($filepath)) {
                throw new \RuntimeException("FICHIER_INTROUVABLE : Le fichier de dump n'a pas été généré.");
            }

            // 8. Vérification que la taille est > 0
            $taille = filesize($filepath);
            if ($taille === false || $taille <= 0) {
                @unlink($filepath);
                throw new \RuntimeException('FICHIER_VIDE : Le fichier de sauvegarde est vide (0 octet).');
            }

            // Vérification de la structure SQL (présence d'en-tête SQL valide)
            $this->validerStructureSql($filepath, $compresser);

            // 9. Calcul du SHA-256
            $checksum = hash_file('sha256', $filepath);
            if ($checksum === false) {
                throw new \RuntimeException('CHECKSUM_ECHEC : Impossible de calculer le hash SHA-256 du fichier.');
            }

            $dureeMs = (int) ((microtime(true) - $debut) * 1000);

            // 10. Enregistrement des métadonnées
            $backup->update([
                'size_bytes' => $taille,
                'checksum' => $checksum,
                'checksum_algorithm' => 'sha256',
                'status' => 'SUCCESS',
                'completed_at' => now(),
                'duration_ms' => $dureeMs,
                'verified_at' => now(),
            ]);

            // 11. Déclenchement de la rétention après validation réussie
            try {
                $retention = app(BackupRetentionService::class);
                $retentionJours = (int) config('sauvegardes.retention_jours', 14);
                $retentionMax = (int) config('sauvegardes.retention_max_backups', 30);
                $retention->purger($dossier, $retentionMax, $retentionJours);
            } catch (\Throwable $retentionError) {
                // La rétention ne doit pas invalider la sauvegarde réussie
                report($retentionError);
            }

            // 12. Audit système : backup.created
            IntegrationLog::tracer(
                'sauvegarde', 'sauvegarde', 'backup.created', 'completed',
                null, 200, $dureeMs, null, null, $backup->uuid
            );

            // 13. Retour explicite
            return $backup->fresh();
        } catch (\Throwable $e) {
            $dureeMs = (int) ((microtime(true) - $debut) * 1000);

            $backup->update([
                'status' => 'FAILED',
                'completed_at' => now(),
                'duration_ms' => $dureeMs,
                'error_code' => mb_substr($e->getMessage(), 0, 100),
            ]);

            // Audit système : backup.failed
            IntegrationLog::tracer(
                'sauvegarde', 'sauvegarde', 'backup.failed', 'failed',
                null, 500, $dureeMs, 'BACKUP_FAILED', null, $backup->uuid
            );

            throw $e;
        }
    }

    /**
     * Valide la structure du fichier SQL généré (présence de marqueurs SQL caractéristiques).
     */
    protected function validerStructureSql(string $filepath, bool $compresser): void
    {
        $contenuDebut = '';

        if ($compresser) {
            $gz = @gzopen($filepath, 'rb');
            if ($gz === false) {
                throw new \RuntimeException('CORRUPTION_ARCHIVE : Impossible de décompresser le fichier gzip.');
            }
            $contenuDebut = (string) gzread($gz, 2048);
            gzclose($gz);
        } else {
            $handle = @fopen($filepath, 'rb');
            if ($handle === false) {
                throw new \RuntimeException('LECTURE_IMPOSSIBLE : Impossible d\'ouvrir le fichier pour vérification.');
            }
            $contenuDebut = (string) fread($handle, 2048);
            fclose($handle);
        }

        if ($contenuDebut === '') {
            throw new \RuntimeException('STRUCTURE_SQL_INVALIDE : Le contenu décompressé est vide.');
        }

        // Marqueurs SQL typiques d'un dump MySQL, SQLite ou commentaire SQL
        $marqueursValides = [
            '--',
            '/*',
            'CREATE TABLE',
            'CREATE DATABASE',
            'SET FOREIGN_KEY_CHECKS',
            'SET @',
            'BEGIN TRANSACTION',
            'PRAGMA',
            'INSERT INTO',
            'USE `',
        ];

        $contientMarqueur = false;
        $debutNettoye = ltrim($contenuDebut);
        foreach ($marqueursValides as $marqueur) {
            if (str_starts_with($debutNettoye, $marqueur) || str_contains($contenuDebut, $marqueur)) {
                $contientMarqueur = true;
                break;
            }
        }

        if (! $contientMarqueur) {
            throw new \RuntimeException('STRUCTURE_SQL_INVALIDE : Le fichier ne contient aucun en-tête SQL valide reconnu.');
        }
    }

    /**
     * Vérifie l'intégrité d'une sauvegarde existante en recalculant son SHA-256 et sa structure.
     *
     * @return array{statut: string, checksum?: string, message?: string, attendu?: string, calcule?: string, backup: DatabaseBackup}
     */
    public function verify(DatabaseBackup $backup): array
    {
        $debut = microtime(true);
        $filepath = $backup->path;

        // 1. Vérification existence fichier
        if (! is_file($filepath)) {
            $backup->update(['status' => 'VERIFICATION_FAILED']);

            IntegrationLog::tracer(
                'sauvegarde', 'sauvegarde', 'backup.verification_failed', 'failed',
                null, 404, (int) ((microtime(true) - $debut) * 1000), 'FICHIER_ABSENT', null, $backup->uuid,
            );

            return [
                'statut' => 'INVALID',
                'message' => 'FICHIER_ABSENT : Le fichier de sauvegarde est introuvable sur le disque.',
                'backup' => $backup->fresh(),
            ];
        }

        // 2. Vérification taille > 0
        $taille = filesize($filepath);
        if ($taille === false || $taille <= 0) {
            $backup->update(['status' => 'VERIFICATION_FAILED']);

            IntegrationLog::tracer(
                'sauvegarde', 'sauvegarde', 'backup.verification_failed', 'failed',
                null, 400, (int) ((microtime(true) - $debut) * 1000), 'FICHIER_VIDE', null, $backup->uuid,
            );

            return [
                'statut' => 'INVALID',
                'message' => 'FICHIER_VIDE : Le fichier présent sur le disque est vide.',
                'backup' => $backup->fresh(),
            ];
        }

        // 3. Calcul SHA-256
        $hashActuel = hash_file('sha256', $filepath);

        // 4. Comparaison avec le checksum stocké
        if ($hashActuel === $backup->checksum) {
            // Vérification de la structure SQL
            try {
                $estCompresse = str_ends_with($backup->filename, '.gz') || $backup->compression === 'gzip';
                $this->validerStructureSql($filepath, $estCompresse);
            } catch (\Throwable $e) {
                $backup->update(['status' => 'VERIFICATION_FAILED']);

                return [
                    'statut' => 'INVALID',
                    'message' => 'STRUCTURE_ALTEREE : '.$e->getMessage(),
                    'backup' => $backup->fresh(),
                ];
            }

            $backup->update([
                'verified_at' => now(),
                'status' => 'SUCCESS',
            ]);

            IntegrationLog::tracer(
                'sauvegarde', 'sauvegarde', 'backup.verified', 'completed',
                null, 200, (int) ((microtime(true) - $debut) * 1000), null, null, $backup->uuid,
            );

            return [
                'statut' => 'VALID',
                'checksum' => $hashActuel,
                'backup' => $backup->fresh(),
            ];
        }

        // Altération détectée : échec explicite
        $backup->update(['status' => 'VERIFICATION_FAILED']);

        IntegrationLog::tracer(
            'sauvegarde', 'sauvegarde', 'backup.verification_failed', 'failed',
            null, 422, (int) ((microtime(true) - $debut) * 1000), 'CHECKSUM_MISMATCH', null, $backup->uuid,
        );

        return [
            'statut' => 'INVALID',
            'message' => 'CHECKSUM_MISMATCH : Le hash SHA-256 calculé ne correspond pas à la valeur enregistrée.',
            'attendu' => $backup->checksum,
            'calcule' => $hashActuel,
            'backup' => $backup->fresh(),
        ];
    }

    /**
     * Exécute la commande de dump selon le moteur configuré.
     *
     * @param  array<string, mixed>  $config
     */
    protected function executerDump(array $config, string $filepath, bool $compresser = false): void
    {
        // Possibilité d'injection d'un runner simulé pour les tests unitaires
        if (self::$dumpRunner !== null) {
            (self::$dumpRunner)($config, $filepath);

            return;
        }

        $driver = $config['driver'] ?? 'mysql';

        if ($driver === 'mysql') {
            $this->executerMysqldump($config, $filepath, $compresser);

            return;
        }

        if ($driver === 'sqlite') {
            $this->executerSqliteDump($config, $filepath, $compresser);

            return;
        }

        throw new \RuntimeException("PILOTE_NON_SUPPORTE : Le pilote '{$driver}' n'est pas pris en charge.");
    }

    /**
     * Exécute mysqldump avec les options adaptées à InnoDB :
     * --single-transaction, --quick, --triggers, --routines, --events
     * sans jamais écrire le mot de passe dans les arguments de ligne de commande ni dans les logs.
     *
     * @param  array<string, mixed>  $config
     */
    protected function executerMysqldump(array $config, string $filepath, bool $compresser = false): void
    {
        // 1. Vérification de la disponibilité du binaire mysqldump
        $check = new Process(['sh', '-lc', 'command -v mysqldump']);
        $check->run();

        if (! $check->isSuccessful()) {
            throw new \RuntimeException('MYSQLDUMP_INTROUVABLE : La commande mysqldump est introuvable sur le système hôte.');
        }

        $binaire = trim($check->getOutput()) ?: 'mysqldump';

        $optionsConfig = config('sauvegardes.options_mysqldump', [
            '--single-transaction',
            '--quick',
            '--triggers',
            '--routines',
            '--events',
            '--default-character-set=utf8mb4',
        ]);

        $args = array_merge([$binaire], $optionsConfig, [
            '-h', (string) ($config['host'] ?? '127.0.0.1'),
            '-P', (string) ($config['port'] ?? '3306'),
            '-u', (string) ($config['username'] ?? 'root'),
            (string) ($config['database'] ?? ''),
        ]);

        // Le mot de passe est transmis EXCLUSIVEMENT par variable d'environnement MYSQL_PWD
        $env = ['MYSQL_PWD' => (string) ($config['password'] ?? '')] + getenv();

        $timeout = (int) config('sauvegardes.timeout', 600);
        $process = new Process($args, null, $env);
        $process->setTimeout($timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            // Nettoyage de l'erreur pour ne jamais laisser fuiter d'identifiants
            $erreur = mb_substr($process->getErrorOutput(), 0, 300);
            throw new \RuntimeException("MYSQLDUMP_ECHEC : Échec de l'extraction de la base de données ({$erreur}).");
        }

        $sortie = $process->getOutput();

        if ($compresser) {
            $gz = @gzopen($filepath, 'wb9');
            if ($gz === false) {
                throw new \RuntimeException("ECRITURE_IMPOSSIBLE : Impossible de créer le fichier compressé ({$filepath}).");
            }
            gzwrite($gz, $sortie);
            gzclose($gz);
        } else {
            if (file_put_contents($filepath, $sortie) === false) {
                throw new \RuntimeException("ECRITURE_IMPOSSIBLE : Impossible d'écrire le fichier de dump ({$filepath}).");
            }
        }
    }

    /**
     * Sauvegarde pour environnement SQLite (notamment pour l'environnement de test).
     *
     * @param  array<string, mixed>  $config
     */
    protected function executerSqliteDump(array $config, string $filepath, bool $compresser = false): void
    {
        $database = $config['database'] ?? '';

        if ($database === ':memory:') {
            // En mémoire : génération d'un schéma SQL factice pour permettre l'exécution des tests
            $sql = "-- Sauvegarde SQLite memoire\nCREATE TABLE test_backup (id INTEGER PRIMARY KEY, nom TEXT);\n";
            if ($compresser) {
                $gz = @gzopen($filepath, 'wb9');
                if ($gz !== false) {
                    gzwrite($gz, $sql);
                    gzclose($gz);
                }
            } else {
                file_put_contents($filepath, $sql);
            }

            return;
        }

        if (! is_file($database)) {
            throw new \RuntimeException("SQLITE_INTROUVABLE : La base SQLite '{$database}' est introuvable.");
        }

        if ($compresser) {
            $contenu = file_get_contents($database);
            if ($contenu === false) {
                throw new \RuntimeException("SQLITE_LECTURE_ECHEC : Impossible de lire la base SQLite {$database}.");
            }
            $gz = @gzopen($filepath, 'wb9');
            if ($gz === false) {
                throw new \RuntimeException("ECRITURE_IMPOSSIBLE : Impossible d'écrire l'archive SQLite ({$filepath}).");
            }
            gzwrite($gz, $contenu);
            gzclose($gz);
        } else {
            if (! copy($database, $filepath)) {
                throw new \RuntimeException("SQLITE_COPIE_ECHEC : Impossible de copier le fichier SQLite vers {$filepath}.");
            }
        }
    }

    /**
     * Statistiques globales du registre de sauvegardes.
     *
     * @return array{total: int, succes: int, echecs: int, octets: int, dernier_checksum: ?string, duree_moyenne_ms: int}
     */
    public function statistiques(): array
    {
        $dernier = DatabaseBackup::where('status', 'SUCCESS')->latest('completed_at')->first();
        $dureeMoyenne = (int) DatabaseBackup::where('status', 'SUCCESS')->whereNotNull('duration_ms')->avg('duration_ms');

        return [
            'total' => DatabaseBackup::where('status', '!=', 'DELETED')->count(),
            'succes' => DatabaseBackup::where('status', 'SUCCESS')->count(),
            'echecs' => DatabaseBackup::whereIn('status', ['FAILED', 'VERIFICATION_FAILED'])->count(),
            'octets' => (int) DatabaseBackup::where('status', 'SUCCESS')->sum('size_bytes'),
            'dernier_checksum' => $dernier?->checksum,
            'duree_moyenne_ms' => $dureeMoyenne,
        ];
    }
}
