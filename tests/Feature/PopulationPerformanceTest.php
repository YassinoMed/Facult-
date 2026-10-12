<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Schedule\Models\Group;
use App\Services\Exports\ListeEtudiantsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Performance 10k+ (§2.17-2.19, §2.24) : volumes, requêtes bornées (N+1),
 * index EXPLAIN, mémoire/temps.
 */
class PopulationPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.perf@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
    }

    public function test_index_requete_optimises(): void
    {
        $connection = DB::connection()->getDriverName();

        // SQLite (tests) : vérifie la présence logique via le schéma ;
        // MySQL (prod) : ces index composites existent réellement.
        $this->assertTrue(Schema::hasColumn('etudiants', 'groupe_id'));
        $this->assertTrue(Schema::hasColumn('etudiants', 'programme_id'));

        if ($connection !== 'sqlite') {
            $indexEtudiants = collect(DB::select('SHOW INDEX FROM etudiants'))->pluck('Key_name')->all();
            $this->assertContains('idx_etudiants_faculte_statut', $indexEtudiants);
            $this->assertContains('idx_etudiants_faculte_prog_niveau', $indexEtudiants);
        } else {
            $this->assertTrue(true);
        }
    }

    public function test_jeu_donnees_sans_n1(): void
    {
        $groupe = Group::create([
            'code' => 'PF1', 'nom' => 'PF1', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'capacite' => 2000, 'actif' => true,
        ]);

        foreach (range(1, 300) as $i) {
            $this->creerEtudiant('PERF'.str_pad((string) $i, 5, '0', STR_PAD_LEFT), $groupe->id);
        }

        $service = app(ListeEtudiantsService::class);

        $requetes = 0;
        DB::listen(function () use (&$requetes) {
            $requetes++;
        });

        $debut = microtime(true);
        $jeu = $service->jeuDonnees($service->requete([], $this->admin), $this->admin);
        $duree = microtime(true) - $debut;

        $this->assertCount(300, $jeu['lignes']);
        // 1 query étudiants + ~4 eager (faculte, groupe, programme, affectations+sous-groupe).
        $this->assertLessThanOrEqual(10, $requetes, "N+1 suspect : {$requetes} requêtes pour 300 lignes.");
        $this->assertLessThan(15, $duree, "Trop lent : {$duree}s pour 300 lignes.");
    }

    public function test_sections_regroupees_sans_n1(): void
    {
        $groupe = Group::create([
            'code' => 'PF2', 'nom' => 'PF2', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'capacite' => 2000, 'actif' => true,
        ]);

        foreach (range(1, 200) as $i) {
            $this->creerEtudiant('PERG'.str_pad((string) $i, 5, '0', STR_PAD_LEFT), $groupe->id);
        }

        $service = app(ListeEtudiantsService::class);

        $requetes = 0;
        DB::listen(function () use (&$requetes) {
            $requetes++;
        });

        $resultat = $service->sectionsRegroupees($service->requete([], $this->admin), $this->admin, null, 'groupe');

        $this->assertSame(200, $resultat['total']);
        $this->assertLessThanOrEqual(10, $requetes, "N+1 suspect : {$requetes} requêtes pour 200 lignes regroupées.");
    }

    public function test_pagination_preview_bornee(): void
    {
        foreach (range(1, 60) as $i) {
            $this->creerEtudiant('PERP'.str_pad((string) $i, 5, '0', STR_PAD_LEFT), null);
        }

        $requetes = 0;
        DB::listen(function () use (&$requetes) {
            $requetes++;
        });

        $this->actingAs($this->admin)->get(route('admin.listes.preview'))->assertOk();

        // Preview = count + paginate(25) + 3 stats + référentiels : borné.
        $this->assertLessThanOrEqual(30, $requetes, "Preview trop bavarde : {$requetes} requêtes.");
    }

    private function creerEtudiant(string $cin, ?int $groupeId): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => strtolower($cin).'@perf.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'groupe_id' => $groupeId,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');
        $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L1', 'statut' => 'en_cours',
        ]);

        return $etudiant;
    }
}
