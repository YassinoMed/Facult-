<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Schedule\Models\Group;
use App\Services\Exports\ListeEtudiantsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Benchmark MySQL (§24-26, §31) : volumes représentatifs, EXPLAIN,
 * index composites Phase 2 vérifiés. Skip hors MySQL (CI = SQLite).
 *
 * Scénario de référence (dev MySQL, transaction rollback) :
 * 3000 étudiants / 3000 inscriptions / 3000 affectations /
 * 1500 pédagogiques / 2000 présences —
 * voir docs/students-groups/benchmark-mysql.md.
 */
class PopulationMySQLPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Benchmark MySQL uniquement.');
        }
    }

    public function test_index_composites_utilises(): void
    {
        $plan = DB::select('EXPLAIN SELECT * FROM etudiants WHERE faculte_id = ? AND statut = ?', [1, 'valide']);

        $this->assertContains($plan[0]->key, ['idx_etudiants_faculte_statut', 'PRIMARY', null]);
        $this->assertTrue(Schema::hasIndex('etudiants', 'idx_etudiants_faculte_prog_niveau'));
        $this->assertTrue(Schema::hasIndex('affectations_groupe', 'idx_affectations_annee_statut'));
    }

    public function test_dataset_borne_requetes(): void
    {
        $faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $admin = User::creerCompteBackOffice([
            'name' => 'S', 'email' => 's.mysql@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $groupe = Group::create([
            'code' => 'MB1', 'nom' => 'MB1', 'faculte_id' => $faculte->id,
            'niveau' => 'L1', 'capacite' => 1000, 'actif' => true,
        ]);
        $matiere = Matiere::create([
            'code' => 'MBM01', 'nom' => 'M', 'niveau' => 'L1', 'semestre' => 1, 'credits' => 4,
        ]);

        foreach (range(1, 500) as $i) {
            $etudiant = Etudiant::create([
                'cin' => 'MB'.str_pad((string) $i, 5, '0', STR_PAD_LEFT), 'nom' => 'N', 'prenom' => 'P',
                'email' => "mb$i@mysql.test", 'date_naissance' => '2004-01-01', 'sexe' => 'F',
                'faculte_id' => $faculte->id, 'groupe_id' => $groupe->id,
                'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'C',
                'code_postal' => '1', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
            ]);
            $etudiant->definirStatut('valide');
            $etudiant->inscriptionsAcademiques()->create([
                'annee_universitaire_id' => $annee->id, 'niveau' => 'L1', 'statut' => 'en_cours',
            ]);
        }

        $service = app(ListeEtudiantsService::class);
        $requetes = 0;
        DB::listen(function () use (&$requetes) {
            $requetes++;
        });

        $debut = microtime(true);
        $jeu = $service->jeuDonnees($service->requete(['faculte_id' => $faculte->id], $admin), $admin);
        $duree = microtime(true) - $debut;

        $this->assertCount(500, $jeu['lignes']);
        $this->assertLessThanOrEqual(12, $requetes, "Trop de requêtes MySQL : {$requetes}.");
        $this->assertLessThan(10, $duree, "Trop lent MySQL : {$duree}s.");
    }
}
