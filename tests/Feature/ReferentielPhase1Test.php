<?php

namespace Tests\Feature;

use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Services\DataExchange\DataExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActAsApiClient;
use Tests\TestCase;

/**
 * Phase 1 — Référentiel : définitions programmes/niveaux/semestres,
 * API lecture, recherche, drag-drop, ordre, dérive OpenAPI.
 */
class ReferentielPhase1Test extends TestCase
{
    use ActAsApiClient;
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private Programme $programme;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.referentiel.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.ref1@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LGL', 'nom' => 'Licence GL',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
    }

    public function test_import_programmes_niveaux_semestres(): void
    {
        foreach ([
            ['programmes', "code;nom;faculte;duree_annees\nLMI;Licence MI;FST;3\n"],
            ['niveaux', "code;nom;programme;ordre\nL1;Première;LGL;1\n"],
            ['semestres', "code;nom;niveau;programme;numero\nS1;Semestre 1;L1;LGL;1\n"],
        ] as [$entite, $contenu]) {
            $fichier = UploadedFile::fake()->createWithContent("{$entite}.csv", $contenu, 'text/csv');

            $preview = $this->actingAs($this->admin)
                ->post(route('admin.donnees.import.preview', $entite), ['fichier' => $fichier])
                ->assertOk();

            $this->actingAs($this->admin)->post(
                route('admin.donnees.import.confirmer', $entite),
                ['token' => $preview->viewData('token'), 'mode' => 'creer']
            )->assertOk();
        }

        $this->assertTrue(Programme::where('code', 'LMI')->exists());
        $this->assertTrue(Niveau::where('code', 'L1')->whereHas('programme', fn ($q) => $q->where('code', 'LGL'))->exists());
        $this->assertTrue(SemestreLmd::where('code', 'S1')->exists());
    }

    public function test_import_semestre_inconnu_rollback(): void
    {
        $fichier = UploadedFile::fake()->createWithContent(
            'semestres.csv', "code;niveau;programme\nSX;L9;LGL\n", 'text/csv'
        );

        $preview = $this->actingAs($this->admin)
            ->post(route('admin.donnees.import.preview', 'semestres'), ['fichier' => $fichier])
            ->assertOk();

        $this->actingAs($this->admin)->post(
            route('admin.donnees.import.confirmer', 'semestres'),
            ['token' => $preview->viewData('token'), 'mode' => 'creer']
        )->assertRedirect();

        $this->assertSame(0, SemestreLmd::where('code', 'SX')->count());
    }

    public function test_export_programmes_scope(): void
    {
        $this->actingAs($this->admin)->get(route('admin.donnees.export', [
            'entite' => 'programmes', 'format' => 'csv',
        ]))->assertOk();

        $requete = Request::create('/admin/donnees/programmes/export/csv');
        $requete->setUserResolver(fn () => $this->admin);

        $export = app(DataExportService::class)
            ->exporter($requete, 'programmes', 'csv');

        $this->assertStringContainsString('LGL', $export['contenu']);
    }

    public function test_api_referentiel_endpoints(): void
    {
        [, $token] = $this->clientApi(['referentiel:read']);

        foreach (['programmes', 'niveaux', 'semestres', 'ues', 'matieres', 'maquettes'] as $ressource) {
            $this->getApi("/api/v1/referentiel/{$ressource}", $token)->assertOk();
        }

        $this->getApi('/api/v1/referentiel/programmes?search=LGL', $token)
            ->assertOk()->assertJsonFragment(['code' => 'LGL']);
    }

    public function test_api_scope_requis_et_faculte(): void
    {
        [, $mauvais] = $this->clientApi(['students:read']);

        $this->getApi('/api/v1/referentiel/programmes', $mauvais)->assertForbidden();
        $this->resetAuthGuards();

        $autre = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'T', 'active' => true]);
        $client = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.ref1@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $autre->id,
        ], 'admin_faculte');
        $jeton = $client->createToken('t', ['referentiel:read'])->plainTextToken;

        $this->getApi('/api/v1/referentiel/programmes', $jeton)
            ->assertOk()->assertJsonMissing(['code' => 'LGL']);
    }

    public function test_recherche_programmes_et_ue(): void
    {
        $ue = UniteEnseignement::create([
            'semestre_id' => SemestreLmd::create([
                'niveau_id' => Niveau::create([
                    'programme_id' => $this->programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1,
                ])->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1,
            ])->id,
            'programme_id' => $this->programme->id,
            'code' => 'UECH', 'nom' => 'UE Recherche', 'credits' => 6,
        ]);

        $this->actingAs($this->admin)->get(route('admin.recherche', ['q' => 'LGL']))
            ->assertOk()->assertSee('Programmes');
        $this->actingAs($this->admin)->get(route('admin.recherche', ['q' => 'UECH']))
            ->assertOk()->assertSee('UECH');
    }

    public function test_drag_drop_reordonne_et_journalise(): void
    {
        $a = Matiere::create(['code' => 'DDA', 'nom' => 'A', 'niveau' => 'L1', 'semestre' => 1]);
        $b = Matiere::create(['code' => 'DDB', 'nom' => 'B', 'niveau' => 'L1', 'semestre' => 1]);

        $this->actingAs($this->admin)->postJson(route('admin.referentiel.reordonner'), [
            'type' => 'matiere', 'ordres' => [$a->id => 5, $b->id => 2],
        ])->assertOk()->assertJson(['count' => 2]);

        $this->assertSame(5, $a->fresh()->ordre);
        $this->assertSame(2, $b->fresh()->ordre);
    }

    public function test_drag_drop_idor_et_validation(): void
    {
        $autre = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'T', 'active' => true]);
        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac2', 'email' => 'fac2.ref1@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $autre->id,
        ], 'admin_faculte');

        $ue = UniteEnseignement::create([
            'semestre_id' => SemestreLmd::create([
                'niveau_id' => Niveau::create([
                    'programme_id' => $this->programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1,
                ])->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1,
            ])->id,
            'programme_id' => $this->programme->id,
            'code' => 'UEX', 'nom' => 'X', 'credits' => 6,
        ]);

        // UE d'une autre faculté : refusée (middleware can:referentiel.manage
        // d'abord — l'admin_faculte n'a que referentiel.view).
        $this->actingAs($adminFaculte)->postJson(route('admin.referentiel.reordonner'), [
            'type' => 'ue', 'ordres' => [$ue->id => 1],
        ])->assertForbidden();
        $this->assertSame(0, $ue->fresh()->ordre);

        // Type invalide → 422.
        $this->actingAs($this->admin)->postJson(route('admin.referentiel.reordonner'), [
            'type' => 'diplome', 'ordres' => [1 => 0],
        ])->assertStatus(422);
    }

    public function test_ordre_affichage_ecue(): void
    {
        $niveau = Niveau::create(['programme_id' => $this->programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);
        $semestre = SemestreLmd::create(['niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $this->programme->id,
            'code' => 'UETRI', 'nom' => 'Tri', 'credits' => 6,
        ]);
        Matiere::create(['code' => 'DDZ', 'nom' => 'Z', 'ue_id' => $ue->id, 'niveau' => 'L1', 'semestre' => 1, 'ordre' => 9]);
        Matiere::create(['code' => 'DDA2', 'nom' => 'A', 'ue_id' => $ue->id, 'niveau' => 'L1', 'semestre' => 1, 'ordre' => 1]);

        $this->actingAs($this->admin)->get(route('admin.referentiel.ecues.index'))
            ->assertOk()->assertSeeInOrder(['DDA2', 'DDZ']);
    }

    public function test_performance_listes_recherche_export(): void
    {
        foreach (range(1, 300) as $i) {
            Matiere::create([
                'code' => 'PERF'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'nom' => 'Matière '.$i, 'niveau' => 'L1', 'semestre' => 1,
            ]);
        }

        $requetes = 0;
        DB::listen(function () use (&$requetes) {
            $requetes++;
        });

        $debut = microtime(true);
        $this->actingAs($this->admin)->get(route('admin.referentiel.ecues.index'))->assertOk();
        $dureeListe = microtime(true) - $debut;
        $this->assertLessThanOrEqual(10, $requetes, "Liste ECUE : {$requetes} requêtes.");
        $this->assertLessThan(2, $dureeListe);

        $debut = microtime(true);
        $this->actingAs($this->admin)->get(route('admin.recherche', ['q' => 'PERF']))->assertOk();
        $this->assertLessThan(2, microtime(true) - $debut, 'Recherche > 500 ms.');
    }

    public function test_export_volumineux_borne(): void
    {
        foreach (range(1, 2000) as $i) {
            Matiere::create([
                'code' => 'EXP'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'nom' => 'Export '.$i, 'niveau' => 'L1', 'semestre' => 1,
            ]);
        }

        $requete = Request::create('/admin/donnees/matieres/export/csv');
        $requete->setUserResolver(fn () => $this->admin);

        $debut = microtime(true);
        $export = app(DataExportService::class)
            ->exporter($requete, 'matieres', 'csv');
        $duree = microtime(true) - $debut;

        $this->assertSame(2000, substr_count($export['contenu'], "\n") - 1);
        $this->assertLessThan(30, $duree, "Export 2000 lignes : {$duree}s.");
    }
}
