<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Referentiel\Services\MaquetteActivationSimulationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prévalidation des maquettes (ENTERPRISE LOOP 02) : rapport BLOCKER /
 * WARNING / INFO avec règle, gravité et correction — lecture seule,
 * périmètre facultaire, activation refusée hors conformité.
 */
class ReferentielPrevalidationTest extends TestCase
{
    use RefreshDatabase;

    private User $scolarite;

    private User $adminFacA;

    private Programme $programmeA;

    private Programme $programmeB;

    private Maquette $maquetteA;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.referentiel.active' => true]);

        $faculteA = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $faculteB = Faculte::create(['nom' => 'FDL', 'code' => 'FDL', 'active' => true]);

        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.preval@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->adminFacA = User::creerCompteBackOffice([
            'name' => 'Admin A', 'email' => 'a.preval@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $faculteA->id,
        ], 'admin_faculte');

        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $this->programmeA = Programme::create([
            'faculte_id' => $faculteA->id, 'code' => 'PA-PRE', 'nom' => 'Programme A prévalidation',
            'statut' => 'actif', 'actif' => true, 'credits_requis' => 180,
        ]);
        $this->programmeB = Programme::create([
            'faculte_id' => $faculteB->id, 'code' => 'PB-PRE', 'nom' => 'Programme B prévalidation',
            'statut' => 'actif', 'actif' => true, 'credits_requis' => 180,
        ]);

        $niveau = Niveau::create([
            'programme_id' => $this->programmeA->id, 'code' => 'L1', 'nom' => 'L1',
            'ordre' => 1, 'actif' => true,
        ]);
        $semestre = SemestreLmd::create([
            'niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1',
            'numero' => 1, 'ordre' => 1, 'actif' => true,
        ]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $this->programmeA->id,
            'code' => 'UE-PRE', 'nom' => 'UE prévalidation', 'credits' => 6, 'actif' => true,
        ]);
        Matiere::create([
            'code' => 'EC-PRE', 'nom' => 'ECUE prévalidation', 'credits' => 6, 'ue_id' => $ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'volume_cm' => 20, 'actif' => true,
        ]);

        $this->maquetteA = Maquette::create([
            'programme_id' => $this->programmeA->id,
            'annee_universitaire_id' => $annee->id,
            'version' => 1, 'statut' => 'draft',
        ]);
    }

    public function test_prevalidation_index_resume_lectures(): void
    {
        $reponse = $this->actingAs($this->scolarite)->get(route('admin.referentiel.prevalidation.index'));

        $reponse->assertOk();
        $reponse->assertSee('PA-PRE');
        $reponse->assertSee('PB-PRE');
        $reponse->assertSee('En attente');
    }

    public function test_prevalidation_index_scope_facute(): void
    {
        $reponse = $this->actingAs($this->adminFacA)->get(route('admin.referentiel.prevalidation.index'));

        $reponse->assertOk();
        $reponse->assertSee('PA-PRE');
        $reponse->assertDontSee('PB-PRE');
    }

    public function test_prevalidation_detail_bloquants_et_corrections(): void
    {
        $reponse = $this->actingAs($this->scolarite)
            ->get(route('admin.referentiel.prevalidation.show', $this->programmeA));

        $reponse->assertOk();
        $reponse->assertSee('MAQ-03');
        $reponse->assertSee('MAQUETTE_NON_APPROUVEE');
        $reponse->assertSee('En attente — activation interdite');
        $reponse->assertSee('Corrections nécessaires');
    }

    public function test_prevalidation_detail_idor(): void
    {
        $this->actingAs($this->adminFacA)
            ->get(route('admin.referentiel.prevalidation.show', $this->programmeB))
            ->assertForbidden();
    }

    public function test_prevalidation_gravites_et_regles(): void
    {
        $rapport = app(MaquetteActivationSimulationService::class)->simulerProgramme($this->programmeA);

        $this->assertNotEmpty($rapport['blocages']);
        foreach ($rapport['blocages'] as $blocage) {
            $this->assertSame('BLOCKER', $blocage['gravite']);
            $this->assertArrayHasKey('regle', $blocage);
            $this->assertArrayHasKey('code', $blocage);
            $this->assertArrayHasKey('detail', $blocage);
        }
        foreach ($rapport['validations'] as $validation) {
            $this->assertSame('INFO', $validation['gravite']);
        }
        foreach ($rapport['avertissements'] as $avertissement) {
            $this->assertSame('WARNING', $avertissement['gravite']);
        }
    }

    public function test_activation_directe_brouillon_refusee(): void
    {
        // Transition draft → active interdite par TRANSITIONS : le workflow
        // technique seul ne suffit jamais (règle absolue n°5).
        $reponse = $this->actingAs($this->scolarite)->patch(
            route('admin.referentiel.maquettes.transition', $this->maquetteA), ['statut' => 'active']
        );

        $reponse->assertRedirect();
        $reponse->assertSessionHasErrors('statut');
        $this->assertSame('draft', $this->maquetteA->fresh()->statut);
    }

    public function test_prevalidation_necrit_rien(): void
    {
        $avant = [Programme::count(), Maquette::count()];

        $this->actingAs($this->scolarite)->get(route('admin.referentiel.prevalidation.index'))->assertOk();
        $this->actingAs($this->scolarite)
            ->get(route('admin.referentiel.prevalidation.show', $this->programmeA))->assertOk();

        $this->assertSame($avant, [Programme::count(), Maquette::count()]);
    }

    public function test_kit_creation_guidee_contexte_seul(): void
    {
        // B1 : la prévalidation propose la création (programme sans
        // maquette) avec contexte présélectionné — aucun contenu.
        $this->actingAs($this->scolarite)
            ->get(route('admin.referentiel.prevalidation.show', $this->programmeB))
            ->assertOk()
            ->assertSee('Créer la maquette v1');

        $reponse = $this->actingAs($this->scolarite)->get(route('admin.referentiel.maquettes.create', [
            'programme_id' => $this->programmeB->id,
        ]));
        $reponse->assertOk();
        $reponse->assertSee('value="'.$this->programmeB->id.'" selected', false);
    }
}
