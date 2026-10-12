<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\MaquetteUe;
use App\Modules\Referentiel\Models\MaquetteUeEcue;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Referentiel\Services\MatiereStandaloneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Standalone (LOOP 07, temps 1) + charge pédagogique (LOOP 08) :
 * inventaire classé sans rattachement auto, indicateurs descriptifs
 * sans seuils — lecture seule, périmètre vérifié.
 */
class ReferentielStandaloneChargeTest extends TestCase
{
    use RefreshDatabase;

    private User $scolarite;

    private User $adminFacA;

    private Programme $programme;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.referentiel.active' => true]);

        $faculteA = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $faculteB = Faculte::create(['nom' => 'FDL', 'code' => 'FDL', 'active' => true]);
        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.std@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->adminFacA = User::creerCompteBackOffice([
            'name' => 'Admin A', 'email' => 'a.std@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $faculteA->id,
        ], 'admin_faculte');

        $this->programme = Programme::create([
            'faculte_id' => $faculteA->id, 'code' => 'CHG', 'nom' => 'Programme charge',
            'statut' => 'actif', 'actif' => true, 'credits_requis' => 12,
        ]);
        $niveau = Niveau::create([
            'programme_id' => $this->programme->id, 'code' => 'L1', 'nom' => 'L1',
            'ordre' => 1, 'actif' => true,
        ]);
        $semestre = SemestreLmd::create([
            'niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1',
            'numero' => 1, 'ordre' => 1, 'actif' => true,
        ]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $this->programme->id,
            'code' => 'UE-CHG', 'nom' => 'UE charge', 'credits' => 6,
            'type' => 'fondamentale', 'actif' => true,
        ]);
        // Jumeau rattaché → DOCUMENTEE pour la standalone éponyme.
        Matiere::create([
            'code' => 'EC-JUM', 'nom' => 'Matière jumelle', 'credits' => 6, 'ue_id' => $ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'volume_cm' => 30, 'actif' => true,
        ]);
        // Standalone : jumeau + sans piste + usage (occurrence plan).
        $orpheline = Matiere::create([
            'code' => 'ORPH-1', 'nom' => 'Matière jumelle', 'credits' => 2,
            'niveau' => 'L1', 'semestre' => 1, 'volume_cm' => 10, 'actif' => true,
        ]);
        Matiere::create([
            'code' => 'ORPH-2', 'nom' => 'Matière sans piste', 'credits' => 2,
            'niveau' => 'L9', 'semestre' => 9, 'actif' => true,
        ]);
        $maquette = Maquette::create([
            'programme_id' => $this->programme->id,
            'annee_universitaire_id' => AnneeUniversitaire::create([
                'libelle' => '2026-2027', 'date_debut' => '2026-09-01',
                'date_fin' => '2027-06-30', 'active' => true,
            ])->id,
            'version' => 1, 'statut' => 'draft',
        ]);
        $ligne = MaquetteUe::create([
            'maquette_id' => $maquette->id, 'ue_id' => $ue->id, 'semestre_id' => $semestre->id,
        ]);
        MaquetteUeEcue::create([
            'maquette_ue_id' => $ligne->id, 'matiere_id' => $orpheline->id,
            'ordre' => 0, 'coefficient' => 1, 'credits' => 2,
            'regime' => 'obligatoire', 'obligatoire' => true,
        ]);
    }

    public function test_inventaire_classe_sans_rattachement(): void
    {
        $inventaire = app(MatiereStandaloneService::class)->inventaire();

        $this->assertSame(2, $inventaire['resume']['total']);
        $this->assertSame(1, $inventaire['resume']['DOCUMENTEE']);
        $this->assertSame(1, $inventaire['resume']['SANS_CORRESPONDANCE']);

        $jumele = collect($inventaire['matieres'])->firstWhere('code', 'ORPH-1');
        $this->assertSame('DOCUMENTEE', $jumele['categorie']);
        $this->assertSame('EN_ATTENTE', $jumele['etat_migration']);
        $this->assertSame(1, $jumele['usages_total']);

        // Aucune écriture : toujours 2 standalone, aucune UE touchée.
        $this->assertSame(2, Matiere::whereNull('ue_id')->count());
        $this->assertSame(1, UniteEnseignement::count());
    }

    public function test_page_standalone_filtres(): void
    {
        $reponse = $this->actingAs($this->scolarite)->get(route('admin.referentiel.standalone'));
        $reponse->assertOk();
        $reponse->assertSee('ORPH-1');
        $reponse->assertSee('ORPH-2');
        $reponse->assertSee('EN ATTENTE');

        $this->actingAs($this->scolarite)
            ->get(route('admin.referentiel.standalone', ['categorie' => 'SANS_CORRESPONDANCE']))
            ->assertOk()->assertSee('ORPH-2')->assertDontSee('ORPH-1');
    }

    public function test_charges_descriptives_sans_seuils(): void
    {
        $reponse = $this->actingAs($this->scolarite)
            ->get(route('admin.referentiel.charges', ['programme_id' => $this->programme->id]));

        $reponse->assertOk();
        $reponse->assertSee('CHG');
        $reponse->assertSee('Sources :');
        $reponse->assertSee('fondamentale');
        $reponse->assertDontSee('seuil');
    }

    public function test_charges_scope_facute(): void
    {
        // Restreint : son périmètre uniquement (pas de programme B).
        $reponse = $this->actingAs($this->adminFacA)->get(route('admin.referentiel.charges'));
        $reponse->assertOk();
        $reponse->assertSee('CHG');

        // Programme d'une autre faculté : 404 (IDs navigateur jamais foi).
        $faculteB = Faculte::where('code', 'FDL')->firstOrFail();
        $autre = Programme::create([
            'faculte_id' => $faculteB->id, 'code' => 'ZZZ', 'nom' => 'Programme Z',
            'statut' => 'actif', 'actif' => true,
        ]);
        $this->actingAs($this->adminFacA)
            ->get(route('admin.referentiel.charges', ['programme_id' => $autre->id]))
            ->assertNotFound();
    }

    public function test_pages_necrivent_rien(): void
    {
        $avant = [Matiere::count(), UniteEnseignement::count(), Programme::count()];

        $this->actingAs($this->scolarite)->get(route('admin.referentiel.standalone'))->assertOk();
        $this->actingAs($this->scolarite)
            ->get(route('admin.referentiel.charges', ['programme_id' => $this->programme->id]))->assertOk();

        $this->assertSame($avant, [Matiere::count(), UniteEnseignement::count(), Programme::count()]);
    }
}
