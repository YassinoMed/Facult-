<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Services\MaquetteActivationSimulationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sécurité enterprise du référentiel (LOOP 14) : authentification sur
 * toutes les routes du chantier, périmètre facultaire sans fuite,
 * transitions non détourables (mass assignment), API sous scope.
 * Services + chemins directs vérifiés, pas seulement les middlewares.
 */
class ReferentielSecuriteTest extends TestCase
{
    use RefreshDatabase;

    private User $scolarite;

    private User $restreintA;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private Programme $programmeA;

    private Programme $programmeB;

    private Maquette $maquetteA;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.referentiel.active' => true]);

        $this->faculteA = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'FDL', 'code' => 'FDL', 'active' => true]);
        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.sec14@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->restreintA = User::creerCompteBackOffice([
            'name' => 'Admin A', 'email' => 'a.sec14@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        foreach ([[$this->faculteA, 'PA-S14'], [$this->faculteB, 'PB-S14']] as [$faculte, $code]) {
            $programme = Programme::create([
                'faculte_id' => $faculte->id, 'code' => $code, 'nom' => 'Programme '.$code,
                'statut' => 'actif', 'actif' => true,
            ]);

            if ($code === 'PA-S14') {
                $this->programmeA = $programme;
            } else {
                $this->programmeB = $programme;
            }
        }

        $this->maquetteA = Maquette::create([
            'programme_id' => $this->programmeA->id, 'annee_universitaire_id' => $annee->id,
            'version' => 1, 'statut' => 'draft',
        ]);
    }

    public function test_routes_exigent_authentification(): void
    {
        $this->get(route('admin.referentiel.pilotage'))->assertRedirect();
        $this->get(route('admin.referentiel.prevalidation.index'))->assertRedirect();
        $this->get(route('admin.referentiel.prevalidation.show', $this->programmeA))->assertRedirect();
        $this->get(route('admin.referentiel.standalone'))->assertRedirect();
        $this->get(route('admin.referentiel.charges'))->assertRedirect();
        $this->get(route('admin.referentiel.prerequis'))->assertRedirect();
        $this->get(route('admin.referentiel.maquettes.comparer', $this->maquetteA))->assertRedirect();
    }

    public function test_restreint_sans_fuite_inter_facultes(): void
    {
        $agent = $this->actingAs($this->restreintA);

        $agent->get(route('admin.referentiel.pilotage'))->assertOk()
            ->assertSee('PA-S14')->assertDontSee('PB-S14');
        $agent->get(route('admin.referentiel.prevalidation.index'))->assertOk()
            ->assertSee('PA-S14')->assertDontSee('PB-S14');
        $agent->get(route('admin.referentiel.prevalidation.show', $this->programmeB))->assertForbidden();
        $agent->get(route('admin.referentiel.maquettes.comparer', $this->maquetteA))->assertOk();
    }

    public function test_transition_parametre_forge_ignore(): void
    {
        // Mass assignment : seul `statut` est lu, tout autre champ est
        // sans effet sur la maquette comme sur le programme.
        $this->actingAs($this->scolarite)->patch(
            route('admin.referentiel.maquettes.transition', $this->maquetteA),
            ['statut' => 'review', 'programme_id' => $this->programmeB->id, 'statut_force' => 'active']
        )->assertRedirect();

        $this->assertSame('review', $this->maquetteA->fresh()->statut);
        $this->assertSame($this->programmeA->id, (int) $this->maquetteA->fresh()->programme_id);
    }

    public function test_api_referentiel_sous_scope(): void
    {
        // Sans token : 401, jamais de données.
        $this->getJson('/api/v1/referentiel/programmes')->assertUnauthorized();
    }

    public function test_service_simulation_scope_strict(): void
    {
        // Chemin direct (hors HTTP) : le scope faculté exclut l'autre.
        $rapport = app(MaquetteActivationSimulationService::class)
            ->simuler($this->faculteB->id);

        $codes = array_column($rapport['programmes'], 'programme');
        $this->assertContains('PB-S14', $codes);
        $this->assertNotContains('PA-S14', $codes);
    }
}
