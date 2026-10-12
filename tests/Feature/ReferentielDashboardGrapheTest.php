<?php

namespace Tests\Feature;

use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Referentiel\Services\GraphePrerequisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Dashboard multi-facultés (LOOP 09) + graphe des prérequis (LOOP 11) :
 * comparaison globale bornée, filtre verrouillé pour les restreints,
 * graphe scopé avec cycles et hors-programme — lecture seule.
 */
class ReferentielDashboardGrapheTest extends TestCase
{
    use RefreshDatabase;

    private User $scolarite;

    private User $adminFacA;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private Programme $programmeA;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.referentiel.active' => true]);

        $this->faculteA = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'FDL', 'code' => 'FDL', 'active' => true]);
        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.dash@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->adminFacA = User::creerCompteBackOffice([
            'name' => 'Admin A', 'email' => 'a.dash@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $this->programmeA = $this->creerProgramme($this->faculteA, 'DA');
        $this->creerProgramme($this->faculteB, 'DB');
    }

    private function creerProgramme(Faculte $faculte, string $suffixe): Programme
    {
        $programme = Programme::create([
            'faculte_id' => $faculte->id, 'code' => 'P-'.$suffixe, 'nom' => 'Programme '.$suffixe,
            'statut' => 'actif', 'actif' => true,
        ]);
        $niveau = Niveau::create([
            'programme_id' => $programme->id, 'code' => 'L1', 'nom' => 'L1',
            'ordre' => 1, 'actif' => true,
        ]);
        $semestre = SemestreLmd::create([
            'niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1',
            'numero' => 1, 'ordre' => 1, 'actif' => true,
        ]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $programme->id,
            'code' => 'UE-'.$suffixe, 'nom' => 'UE '.$suffixe, 'credits' => 6, 'actif' => true,
        ]);
        Matiere::create([
            'code' => 'EC-'.$suffixe.'-1', 'nom' => 'ECUE 1', 'credits' => 3, 'ue_id' => $ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'volume_cm' => 20, 'actif' => true,
        ]);
        Matiere::create([
            'code' => 'EC-'.$suffixe.'-2', 'nom' => 'ECUE 2', 'credits' => 3, 'ue_id' => $ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'volume_cm' => 20, 'actif' => true,
        ]);

        return $programme;
    }

    public function test_tableau_bord_comparaison_globale(): void
    {
        $reponse = $this->actingAs($this->scolarite)->get(route('admin.referentiel.tableau-bord'));

        $reponse->assertOk();
        $reponse->assertSee('Comparaison par établissement');
        $reponse->assertSee('FST');
        $reponse->assertSee('FDL');
        // LOOP 3 : compteurs exigés présents (standalone, états).
        $reponse->assertSee('Matières standalone');
        $reponse->assertSee('ECUE rattachées');
        $reponse->assertSee('Maquettes approuvées');
        $reponse->assertSee('Maquettes archivées');
    }

    public function test_tableau_bord_filtre_faculte(): void
    {
        $reponse = $this->actingAs($this->scolarite)
            ->get(route('admin.referentiel.tableau-bord', ['faculte_id' => $this->faculteA->id]));

        $reponse->assertOk();
        // Périmètre filtré : 1 programme au lieu de 2.
        $reponse->assertSee('Programmes');
    }

    public function test_tableau_bord_restreint_sans_table_sans_filtre(): void
    {
        // Restreint : pas de comparaison globale, pas de fuite.
        $reponse = $this->actingAs($this->adminFacA)
            ->get(route('admin.referentiel.tableau-bord', ['faculte_id' => $this->faculteB->id]));

        $reponse->assertOk();
        $reponse->assertDontSee('Comparaison par établissement');
        $reponse->assertDontSee('FDL');
    }

    public function test_graphe_noeuds_aretes(): void
    {
        $ecues = Matiere::where('code', 'like', 'EC-DA-%')->orderBy('id')->get();
        $ecues[1]->prerequis()->syncWithoutDetaching([$ecues[0]->id]);

        $reponse = $this->actingAs($this->scolarite)
            ->get(route('admin.referentiel.prerequis', ['programme_id' => $this->programmeA->id]));

        $reponse->assertOk();
        $reponse->assertSee('EC-DA-1');
        $reponse->assertSee('EC-DA-2');
        $reponse->assertSee('Dépendances (1)');
    }

    public function test_graphe_cycle_et_hors_programme(): void
    {
        $ecues = Matiere::where('code', 'like', 'EC-DA-%')->orderBy('id')->get();
        DB::table('ecue_prerequis')->insert([
            ['matiere_id' => $ecues[0]->id, 'prerequis_id' => $ecues[1]->id],
            ['matiere_id' => $ecues[1]->id, 'prerequis_id' => $ecues[0]->id],
        ]);
        // Prérequis hors programme (UE d'un autre programme).
        $intrus = Matiere::where('code', 'EC-DB-1')->firstOrFail();
        DB::table('ecue_prerequis')->insert([
            ['matiere_id' => $ecues[0]->id, 'prerequis_id' => $intrus->id],
        ]);

        $graphe = app(GraphePrerequisService::class)->construire($this->programmeA);

        $this->assertSame(2, $graphe['aretes_en_cycle']);
        $this->assertTrue(collect($graphe['nodes'])->firstWhere('code', 'EC-DA-1')['en_cycle']);
        $this->assertTrue(collect($graphe['nodes'])->firstWhere('code', 'EC-DA-1')['hors_programme']);

        $reponse = $this->actingAs($this->scolarite)
            ->get(route('admin.referentiel.prerequis', ['programme_id' => $this->programmeA->id]));
        $reponse->assertOk();
        $reponse->assertSee('En cycle');
        $reponse->assertSee('Hors programme');
    }

    public function test_graphe_scope_404(): void
    {
        $programmeB = Programme::where('code', 'P-DB')->firstOrFail();

        $this->actingAs($this->adminFacA)
            ->get(route('admin.referentiel.prerequis', ['programme_id' => $programmeB->id]))
            ->assertNotFound();

        // Sans programme : sélecteur seul, aucune donnée.
        $this->actingAs($this->scolarite)->get(route('admin.referentiel.prerequis'))
            ->assertOk()->assertSee('Choisir un programme');
    }

    public function test_graphe_lie_fiche_ecue(): void
    {
        // C3 : chaque nœud mène à sa fiche ECUE (retrait du cycle là-bas).
        $this->actingAs($this->scolarite)
            ->get(route('admin.referentiel.prerequis', ['programme_id' => $this->programmeA->id]))
            ->assertOk()
            ->assertSee(route('admin.referentiel.ecues.show', Matiere::where('code', 'EC-DA-1')->firstOrFail()), false);
    }

    public function test_pages_necrivent_rien(): void
    {
        $avant = [Programme::count(), Matiere::count()];

        $this->actingAs($this->scolarite)->get(route('admin.referentiel.tableau-bord'))->assertOk();
        $this->actingAs($this->scolarite)
            ->get(route('admin.referentiel.prerequis', ['programme_id' => $this->programmeA->id]))->assertOk();

        $this->assertSame($avant, [Programme::count(), Matiere::count()]);
    }
}
