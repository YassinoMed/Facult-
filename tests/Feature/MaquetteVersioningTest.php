<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Referentiel\Services\MaquetteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Versionnage et immutabilité des maquettes (LOOP référentiel §15) :
 * approved/active/archived verrouillées, nouvelle version sans toucher
 * l'originale, une seule active par programme.
 */
class MaquetteVersioningTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Programme $programme;

    private AnneeUniversitaire $annee;

    private UniteEnseignement $ue;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.referentiel.active' => true]);

        $faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.maquette@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->programme = Programme::create([
            'faculte_id' => $faculte->id, 'code' => 'LGL', 'nom' => 'Licence GL',
            'statut' => 'actif', 'actif' => true, 'duree_annees' => 3,
        ]);
        $niveau = Niveau::create([
            'programme_id' => $this->programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1, 'actif' => true,
        ]);
        $semestre = SemestreLmd::create([
            'niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1, 'actif' => true,
        ]);
        $this->ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $this->programme->id,
            'code' => 'UE01', 'nom' => 'UE 01', 'credits' => 30, 'actif' => true,
        ]);
    }

    private function creerMaquette(string $statut = 'draft', int $version = 1): Maquette
    {
        return Maquette::create([
            'programme_id' => $this->programme->id,
            'annee_universitaire_id' => $this->annee->id,
            'version' => $version,
            'statut' => $statut,
        ]);
    }

    private function service(): MaquetteService
    {
        return app(MaquetteService::class);
    }

    public function test_approved_maquette_is_immutable(): void
    {
        $maquette = $this->creerMaquette('approved');

        try {
            $this->service()->definirContenu($maquette, [], $this->admin->id);
            $this->fail('Contenu modifiable sur approved.');
        } catch (\DomainException) {
            $this->assertTrue(true);
        }

        $response = $this->actingAs($this->admin)->put(
            route('admin.referentiel.maquettes.contenu', $maquette), ['ues' => []]
        );
        $response->assertRedirect();
        $response->assertSessionHasErrors('maquette');
    }

    public function test_active_maquette_is_immutable(): void
    {
        $maquette = $this->creerMaquette('active');

        try {
            $this->service()->definirContenu($maquette, [], $this->admin->id);
            $this->fail('Contenu modifiable sur active.');
        } catch (\DomainException) {
            $this->assertTrue(true);
        }
    }

    public function test_archived_maquette_is_immutable(): void
    {
        $maquette = $this->creerMaquette('archived');

        try {
            $this->service()->definirContenu($maquette, [], $this->admin->id);
            $this->fail('Contenu modifiable sur archived.');
        } catch (\DomainException) {
            $this->assertTrue(true);
        }

        // Suppression physique refusée hors brouillon.
        $this->actingAs($this->admin)
            ->delete(route('admin.referentiel.maquettes.destroy', $maquette))
            ->assertRedirect();
        $this->assertDatabaseHas('maquettes', ['id' => $maquette->id]);
    }

    public function test_new_version_does_not_modify_previous_version(): void
    {
        $v1 = $this->creerMaquette('draft');
        $this->service()->definirContenu($v1, [
            ['ue_id' => $this->ue->id, 'semestre_id' => $this->ue->semestre_id],
        ], $this->admin->id);
        $contenuV1 = $v1->unitesEnseignement()->pluck('unites_enseignement.id')->all();

        $v2 = $this->service()->nouvelleVersion($v1, $this->annee->id, $this->admin->id, 'ajustements');

        $this->assertSame('draft', $v2->statut);
        $this->assertSame(2, $v2->version);
        $this->assertEqualsCanonicalizing(
            $contenuV1,
            $v2->unitesEnseignement()->pluck('unites_enseignement.id')->all(),
            'Snapshot du contenu repris.'
        );
        $this->assertEqualsCanonicalizing(
            $contenuV1,
            $v1->fresh()->unitesEnseignement()->pluck('unites_enseignement.id')->all(),
            'Originale intacte.'
        );
    }

    public function test_une_seule_maquette_active_par_programme(): void
    {
        $a = $this->creerMaquette('approved');
        $b = $this->creerMaquette('approved', 2);

        $this->service()->transiter($a, 'active', $this->admin->id);
        $this->service()->transiter($b, 'active', $this->admin->id);

        $this->assertSame('archived', $a->fresh()->statut);
        $this->assertSame('active', $b->fresh()->statut);
        $this->assertSame(1, Maquette::where('programme_id', $this->programme->id)->where('statut', 'active')->count());
    }

    public function test_transition_interdite_refusee(): void
    {
        // archived est terminal : aucune sortie possible (abandon d'un
        // brouillon draft → archived reste, lui, autorisé).
        $maquette = $this->creerMaquette('archived');

        try {
            $this->service()->transiter($maquette, 'draft', $this->admin->id);
            $this->fail('Transition archived → draft acceptée.');
        } catch (\DomainException) {
            $this->assertSame('archived', $maquette->fresh()->statut);
        }
    }
}
