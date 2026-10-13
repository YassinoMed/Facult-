<?php

namespace Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Preuve réelle d'opérabilité (gate de la mission) : mysqldump 9.x sur
 * base dédiée → manifest + --verifier → import mysql vers base vierge →
 * parité schéma + données. AUCUNE autre base touchée (noms imposés,
 * refus sinon). Exécution : BACKUP_ROUNDTRIP=1 (+ mysqldump présent).
 * SANS la variable : skip (la suite standard reste sans effet de bord).
 */
class BackupRoundtripTest extends TestCase
{
    private const SRC = 'backup_roundtrip_src';

    private const DST = 'backup_roundtrip_dst';

    private string $dossier = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('BACKUP_ROUNDTRIP') !== '1') {
            $this->markTestSkipped('Roundtrip réel : BACKUP_ROUNDTRIP=1 requis.');
        }

        $check = new Process(['sh', '-lc', 'command -v mysqldump && command -v mysql']);
        $check->run();

        if (! $check->isSuccessful()) {
            $this->markTestSkipped('Binaires mysqldump/mysql absents.');
        }

        foreach (['backup_src' => self::SRC, 'backup_dst' => self::DST] as $connexion => $base) {
            config(["database.connections.{$connexion}" => [
                'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306',
                'database' => $base, 'username' => 'root', 'password' => 'root',
                'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
            ]]);
        }

        $this->dossier = sys_get_temp_dir().'/roundtrip-'.uniqid();
        mkdir($this->dossier, 0750, true);
    }

    protected function tearDown(): void
    {
        foreach ([self::SRC, self::DST] as $base) {
            try {
                $this->serveur()->statement("DROP DATABASE IF EXISTS `{$base}`");
            } catch (\Throwable) {
            }
        }

        foreach (glob($this->dossier.'/*') ?: [] as $fichier) {
            @unlink($fichier);
        }

        @rmdir($this->dossier);

        parent::tearDown();
    }

    private function serveur(): Connection
    {
        // Connexion d'administration dédiée (base système `mysql`) :
        // phpunit force DB_DATABASE=:memory:, inutilisable côté serveur.
        if (! array_key_exists('backup_admin', (array) config('database.connections'))) {
            config(['database.connections.backup_admin' => [
                'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306',
                'database' => 'mysql', 'username' => 'root', 'password' => 'root',
                'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
            ]]);
        }

        return DB::connection('backup_admin');
    }

    public function test_roundtrip_mysqldump_restauration_parite(): void
    {
        // 1. Bases dédiées (création explicite, jamais d'autre base).
        $this->serveur()->statement('CREATE DATABASE IF NOT EXISTS `'.self::SRC.'` CHARACTER SET utf8mb4');
        $this->serveur()->statement('CREATE DATABASE IF NOT EXISTS `'.self::DST.'` CHARACTER SET utf8mb4');

        // 2. Schéma complet + ligne marqueur.
        Artisan::call('migrate', ['--database' => 'backup_src', '--force' => true]);
        DB::connection('backup_src')->table('facultes')->insert([
            'nom' => 'Faculté Roundtrip', 'code' => 'FRT', 'description' => 'T',
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // 3. Dump réel + manifest + vérification.
        $this->assertSame(0, Artisan::call('sauvegarde:creer', [
            '--dossier' => $this->dossier, '--connexion' => 'backup_src',
            '--sans-documents' => true, '--conserver' => 2,
        ]));

        $manifests = glob($this->dossier.'/manifest-*.json') ?: [];
        $this->assertNotEmpty($manifests);
        $donnees = json_decode((string) file_get_contents($manifests[0]), true);
        $dump = $this->dossier.'/'.basename((string) $donnees['artefacts']['base']['fichier']);
        $this->assertFileExists($dump);
        $this->assertGreaterThan(10000, filesize($dump));

        $this->assertSame(0, Artisan::call('sauvegarde:creer', [
            '--dossier' => $this->dossier, '--verifier' => true,
        ]));

        // 4. Restauration vers la base vierge (client mysql, jamais l'app).
        $import = new Process(['sh', '-lc', 'mysql '.self::DST.' < '.escapeshellarg($dump)], null, ['MYSQL_PWD' => 'root'] + getenv());
        $import->setTimeout(600);
        $import->mustRun();

        // 5. Parité schéma + données.
        $tablesSrc = Schema::connection('backup_src')->getTables();
        $tablesDst = Schema::connection('backup_dst')->getTables();
        $nomsSrc = array_column(array_map(fn ($t) => (array) $t, $tablesSrc), 'name');
        $nomsDst = array_column(array_map(fn ($t) => (array) $t, $tablesDst), 'name');
        sort($nomsSrc);
        sort($nomsDst);
        $this->assertSame($nomsSrc, $nomsDst);
        $this->assertContains('facultes', $nomsDst);

        $marqueur = DB::connection('backup_dst')->table('facultes')->where('code', 'FRT')->first();
        $this->assertNotNull($marqueur);
        $this->assertSame('Faculté Roundtrip', $marqueur->nom);

        fwrite(STDERR, "\n[ROUNDTRIP] tables=".count($nomsDst).' octets='.filesize($dump)." manifest={$manifests[0]}");
    }
}
