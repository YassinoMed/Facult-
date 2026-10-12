<?php

namespace Tests\Feature\Security;

use App\Models\DossierAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * LOOP 12-A — Course concurrente de suppression (MySQL/InnoDB réel).
 *
 * Deux connexions PDO indépendantes sur scratch dédiée :
 *  - T1 verrouille la ligne dossier (FOR UPDATE, non commité) ;
 *  - T2 tente le même verrou → DOIT attendre puis expirer (1205),
 *    preuve d'exclusion mutuelle (jamais de double audit) ;
 *  - rollback T1 → dossier intact, zéro audit (cohérence) ;
 *  - DELETE HTTP → 302 + 1 audit ; rejoué → 404 + toujours 1 audit ;
 *  - deleter autre faculté → 403 + intact.
 *
 * Exécution EXCLUSIVE : ARCHIVES_CONC_MYSQL=1, base scratch
 * `archives_conc_scratch` (jamais dev/prod). Sinon : skip.
 */
class ArchivesConcurrenceMysqlTest extends TestCase
{
    use ActAsScolarite;

    private $conn;

    private int $dossierId;

    protected function setUp(): void
    {
        parent::setUp();

        if (env('ARCHIVES_CONC_MYSQL') !== '1') {
            $this->markTestSkipped('Course MySQL réelle : ARCHIVES_CONC_MYSQL=1 requis (base scratch dédiée).');
        }

        $admin = array_merge(config('database.connections.mysql'), ['database' => null]);
        config(['database.connections.archives_conc_admin' => $admin]);
        DB::connection('archives_conc_admin')->statement('CREATE DATABASE IF NOT EXISTS archives_conc_scratch');

        config(['database.connections.archives_conc' => array_merge(
            config('database.connections.mysql'), ['database' => 'archives_conc_scratch']
        )]);
        config(['database.connections.archives_conc_hold' => array_merge(
            config('database.connections.mysql'), ['database' => 'archives_conc_scratch']
        )]);
        $conn = DB::connection('archives_conc');
        $this->assertSame('archives_conc_scratch', $conn->getDatabaseName(),
            'Garde-fou : ce test ne doit jamais toucher le dataset dev.');

        Artisan::call('migrate', ['--database' => 'archives_conc', '--force' => true]);
        DB::setDefaultConnection('archives_conc');
        $conn->beginTransaction();
        $this->conn = $conn;
    }

    protected function tearDown(): void
    {
        if (isset($this->conn) && env('ARCHIVES_CONC_MYSQL') === '1') {
            $this->conn->rollBack();
        }

        parent::tearDown();
    }

    public function test_serialisation_double_suppression(): void
    {
        $tag = substr(md5(uniqid('', true)), 0, 6);
        $faculteA = Faculte::create(['nom' => 'FA', 'code' => 'FA'.$tag, 'description' => 'T', 'active' => true]);
        $etudiant = Etudiant::create([
            'cin' => 'BK'.random_int(100000, 999999), 'nom' => 'Course', 'prenom' => 'T1',
            'email' => "course12a{$tag}@example.com",
            'sexe' => 'M', 'faculte_id' => $faculteA->id,
            'niveau' => 'L1', 'annee_academique' => '2025-2026',
            'adresse' => '1 rue', 'ville' => 'Rabat',
            'code_postal' => '10000', 'annee_bac' => '2024',
            'password' => 'motdepasse1',
        ]);
        $dossier = DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-CONC-'.$tag, 'date_archivage' => now()->toDateString(),
        ]);
        $this->dossierId = $dossier->id;

        $superAdmin = User::creerCompteBackOffice([
            'name' => 'Super', 'email' => "super12a{$tag}@example.com",
            'password' => 'Motdepasse@123',
        ], 'super_admin');

        // Fixtures commitées (scratch) : aucun verrou ouvert ne fausse la danse.
        $this->conn->commit();
        $this->conn->beginTransaction();

        // T1 (2e connexion) : verrou FOR UPDATE tenu, non commité.
        $hold = DB::connection('archives_conc_hold');
        $hold->beginTransaction();
        $hold->table('archives_etudiants')->where('id', $this->dossierId)->lockForUpdate()->first();

        // T2 : même verrou → expire après ~2 s (1205), jamais de passage en force.
        DB::statement('SET SESSION innodb_lock_wait_timeout = 2');
        $debut = microtime(true);
        try {
            DossierAcademique::whereKey($this->dossierId)->lockForUpdate()->first();
            $this->fail('T2 aurait dû expirer sur le verrou T1.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('1205', $e->getMessage());
        }
        $attente = microtime(true) - $debut;
        $this->assertGreaterThanOrEqual(1.5, $attente, 'T2 doit attendre le verrou, pas passer en force.');

        // Rollback T1 : dossier intact, zéro audit de suppression.
        $hold->rollBack();
        $this->assertDatabaseHas('archives_etudiants', ['id' => $this->dossierId]);
        $this->assertSame(0, HistoriqueAcademique::where(
            'action', HistoriqueAcademique::ACTION_SUPPRESSION_DOSSIER
        )->count());

        // DELETE nominal : 302 + 1 audit ; rejoué : 404 + toujours 1 audit.
        $this->actingAs($superAdmin);
        $this->delete(route('admin.archives.destroy', $dossier), ['motif' => 'Suppression de course.'])
            ->assertRedirect(route('admin.archives.index'));
        $this->delete('/admin/archives/'.$this->dossierId, ['motif' => 'Suppression de course.'])
            ->assertNotFound();
        $this->assertSame(1, HistoriqueAcademique::where(
            'action', HistoriqueAcademique::ACTION_SUPPRESSION_DOSSIER
        )->count());
    }

    public function test_suppression_autre_faculte_refusee(): void
    {
        $tag = substr(md5(uniqid('', true)), 0, 6);
        $faculteA = Faculte::create(['nom' => 'FA', 'code' => 'FA2'.$tag, 'description' => 'T', 'active' => true]);
        $faculteB = Faculte::create(['nom' => 'FB', 'code' => 'FB2'.$tag, 'description' => 'T', 'active' => true]);
        $etudiantB = Etudiant::create([
            'cin' => 'BK'.random_int(100000, 999999), 'nom' => 'Course', 'prenom' => 'T2',
            'email' => "course12b{$tag}@example.com",
            'sexe' => 'M', 'faculte_id' => $faculteB->id,
            'niveau' => 'L1', 'annee_academique' => '2025-2026',
            'adresse' => '1 rue', 'ville' => 'Rabat',
            'code_postal' => '10000', 'annee_bac' => '2024',
            'password' => 'motdepasse1',
        ]);
        $dossierB = DossierAcademique::create([
            'etudiant_id' => $etudiantB->id,
            'matricule' => 'MAT-CONC2-'.$tag, 'date_archivage' => now()->toDateString(),
        ]);

        $adminA = User::creerCompteBackOffice([
            'name' => 'Admin A', 'email' => "admina12{$tag}@example.com",
            'password' => 'Motdepasse@123', 'faculte_id' => $faculteA->id,
        ], 'admin_faculte');
        $this->actingAs($adminA);

        $this->delete(route('admin.archives.destroy', $dossierB), ['motif' => 'Tentative cross-faculté.'])
            ->assertForbidden();
        $this->assertDatabaseHas('archives_etudiants', ['id' => $dossierB->id]);
    }
}
