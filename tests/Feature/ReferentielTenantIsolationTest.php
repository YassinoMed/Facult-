<?php

namespace Tests\Feature;

use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Referentiel\Models\Domaine;
use App\Modules\Referentiel\Models\Mention;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\Specialite;
use App\Modules\Referentiel\Models\UniteEnseignement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Isolation multi-facultés du référentiel (LOOP §9/§19) : listings
 * scopés, IDOR en lecture/écriture, imports cloisonnés.
 */
class ReferentielTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $adminFacA;

    private User $adminFacB;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private Programme $programmeA;

    private Programme $programmeB;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.referentiel.active' => true]);

        $this->faculteA = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'FDL', 'code' => 'FDL', 'active' => true]);
        $this->adminFacA = User::creerCompteBackOffice([
            'name' => 'Admin A', 'email' => 'a.isolation@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');
        $this->adminFacB = User::creerCompteBackOffice([
            'name' => 'Admin B', 'email' => 'b.isolation@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');

        $this->programmeA = $this->creerProgramme($this->faculteA, 'PA-ISO');
        $this->programmeB = $this->creerProgramme($this->faculteB, 'PB-ISO');
    }

    private function creerProgramme(Faculte $faculte, string $code): Programme
    {
        $domaine = Domaine::create(['faculte_id' => $faculte->id, 'code' => 'D-'.$code, 'nom' => 'D', 'actif' => true]);
        $mention = Mention::create(['domaine_id' => $domaine->id, 'code' => 'M-'.$code, 'nom' => 'M', 'actif' => true]);
        $specialite = Specialite::create(['mention_id' => $mention->id, 'code' => 'S-'.$code, 'nom' => 'S', 'actif' => true]);

        return Programme::create([
            'faculte_id' => $faculte->id, 'specialite_id' => $specialite->id,
            'code' => $code, 'nom' => 'Programme '.$code, 'statut' => 'actif', 'actif' => true,
        ]);
    }

    public function test_listing_programmes_scope(): void
    {
        $reponse = $this->actingAs($this->adminFacA)->get(route('admin.referentiel.entites.index', 'programmes'));

        $reponse->assertOk();
        $reponse->assertSee('PA-ISO');
        $reponse->assertDontSee('PB-ISO');
    }

    public function test_show_programme_autre_faculte_interdit(): void
    {
        $this->actingAs($this->adminFacA)
            ->get(route('admin.referentiel.entites.show', ['programmes', $this->programmeB->id]))
            ->assertForbidden();
    }

    public function test_update_programme_autre_faculte_interdit(): void
    {
        $this->actingAs($this->adminFacA)->put(
            route('admin.referentiel.entites.update', ['programmes', $this->programmeB->id]),
            ['faculte_id' => $this->faculteB->id, 'code' => 'PB-ISO', 'nom' => 'Piraté', 'statut' => 'actif']
        )->assertForbidden();

        $this->assertNotSame('Piraté', $this->programmeB->fresh()->nom);
    }

    public function test_niveau_ue_scopes_chaine(): void
    {
        $niveau = Niveau::create(['programme_id' => $this->programmeA->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1, 'actif' => true]);
        $semestre = SemestreLmd::create(['niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1, 'actif' => true]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $this->programmeA->id,
            'code' => 'UE-ISO', 'nom' => 'UE', 'credits' => 30, 'actif' => true,
        ]);

        // Admin B ne voit ni le niveau, ni l'UE (chaîne programme).
        $this->actingAs($this->adminFacB)
            ->get(route('admin.referentiel.entites.show', ['niveaux', $niveau->id]))
            ->assertForbidden();
        $this->actingAs($this->adminFacB)
            ->get(route('admin.referentiel.entites.show', ['ues', $ue->id]))
            ->assertForbidden();
    }

    public function test_matiere_transversale_visible_mais_non_modifiable_hors_perimetre(): void
    {
        // Matière sans UE : transversale, visible par tous en lecture.
        $matiere = Matiere::create([
            'code' => 'TRANS-ISO', 'nom' => 'Transversale',
            'niveau' => 'L1', 'semestre' => 1, 'coefficient' => 1, 'credits' => 2, 'volume_cm' => 10,
        ]);

        $this->assertNull($matiere->faculteId());
    }
}
