<?php

namespace Tests\Feature\Security;

use App\Models\DossierAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * LOOP 8 — Preuve MySQL réelle : le registre (agrégats stats) répond
 * 200 sur MySQL ONLY_FULL_GROUP_BY (le `select(*)` + agrégat sans
 * GROUP BY y levait une 1140/500, invisible sous SQLite).
 *
 * Exécution EXCLUSIVE : ARCHIVES_STATS_MYSQL=1, base scratch dédiée
 * `archives_stats_scratch` (jamais dev/prod). Sinon : skip.
 */
class ArchivesStatsMysqlTest extends TestCase
{
    use ActAsScolarite;

    private $conn;

    protected function setUp(): void
    {
        parent::setUp();

        if (env('ARCHIVES_STATS_MYSQL') !== '1') {
            $this->markTestSkipped('Preuve MySQL réelle : ARCHIVES_STATS_MYSQL=1 requis (base scratch dédiée).');
        }

        $admin = array_merge(config('database.connections.mysql'), ['database' => null]);
        config(['database.connections.archives_stats_admin' => $admin]);
        DB::connection('archives_stats_admin')->statement('CREATE DATABASE IF NOT EXISTS archives_stats_scratch');

        config(['database.connections.archives_stats' => array_merge(
            config('database.connections.mysql'), ['database' => 'archives_stats_scratch']
        )]);
        $conn = DB::connection('archives_stats');
        $this->assertSame('archives_stats_scratch', $conn->getDatabaseName(),
            'Garde-fou : ce test ne doit jamais toucher le dataset dev.');

        Artisan::call('migrate', ['--database' => 'archives_stats', '--force' => true]);
        DB::setDefaultConnection('archives_stats');
        $conn->beginTransaction();
        $this->conn = $conn;
    }

    protected function tearDown(): void
    {
        if (isset($this->conn) && env('ARCHIVES_STATS_MYSQL') === '1') {
            $this->conn->rollBack();
        }

        parent::tearDown();
    }

    public function test_registre_200_avec_stats_sur_mysql(): void
    {
        $faculte = Faculte::on('archives_stats')->create(
            ['nom' => 'FA', 'code' => 'FA', 'description' => 'T', 'active' => true]
        );
        $etudiant = Etudiant::on('archives_stats')->create([
            'cin' => 'BK777777', 'nom' => 'Stats', 'prenom' => 'MySQL',
            'email' => 'statsmysql@example.com',
            'sexe' => 'M', 'faculte_id' => $faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2025-2026',
            'adresse' => '1 rue', 'ville' => 'Rabat',
            'code_postal' => '10000', 'annee_bac' => '2024',
            'password' => 'motdepasse1',
        ]);
        DossierAcademique::on('archives_stats')->create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-MYSQL-1', 'date_archivage' => now()->toDateString(),
        ]);

        $this->scolarite('super_admin');

        $this->get(route('admin.archives.index'))->assertOk()->assertSee('Total dossiers');
    }
}
