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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Centre de pilotage des maquettes (ENTERPRISE LOOP 01) : une ligne par
 * programme, filtres combinables, périmètre facultaire, disponibilité
 * opérationnelle jamais sur-vendue et actions contextuelles gated.
 */
class ReferentielPilotageTest extends TestCase
{
    use RefreshDatabase;

    private User $scolarite;

    private User $adminFacA;

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
            'name' => 'Scolarité', 'email' => 'sco.pilotage@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->adminFacA = User::creerCompteBackOffice([
            'name' => 'Admin A', 'email' => 'a.pilotage@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $this->programmeA = Programme::create([
            'faculte_id' => $this->faculteA->id, 'code' => 'PA-PIL', 'nom' => 'Programme A pilote',
            'statut' => 'actif', 'actif' => true, 'credits_requis' => 180,
        ]);
        $this->programmeB = Programme::create([
            'faculte_id' => $this->faculteB->id, 'code' => 'PB-PIL', 'nom' => 'Programme B pilote',
            'statut' => 'actif', 'actif' => true, 'credits_requis' => 180,
        ]);

        $this->creerUe($this->programmeA, 'L1', 'S1', 'UE-PA-01');
        $this->maquetteA = Maquette::create([
            'programme_id' => $this->programmeA->id,
            'annee_universitaire_id' => $annee->id,
            'version' => 1, 'statut' => 'draft',
        ]);
    }

    private function creerUe(Programme $programme, string $niveauCode, string $semestreCode, string $ueCode): void
    {
        $niveau = Niveau::create([
            'programme_id' => $programme->id, 'code' => $niveauCode, 'nom' => $niveauCode,
            'ordre' => 1, 'actif' => true,
        ]);
        $semestre = SemestreLmd::create([
            'niveau_id' => $niveau->id, 'code' => $semestreCode, 'nom' => $semestreCode,
            'numero' => 1, 'ordre' => 1, 'actif' => true,
        ]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $programme->id,
            'code' => $ueCode, 'nom' => 'UE '.$ueCode, 'credits' => 6, 'actif' => true,
        ]);
        Matiere::create([
            'code' => 'EC-'.$ueCode, 'nom' => 'ECUE '.$ueCode, 'credits' => 6, 'ue_id' => $ue->id,
            'niveau' => $niveauCode, 'semestre' => 1, 'volume_cm' => 20, 'actif' => true,
        ]);
    }

    public function test_pilotage_affiche_les_programmes_avec_disponibilite(): void
    {
        $reponse = $this->actingAs($this->scolarite)->get(route('admin.referentiel.pilotage'));

        $reponse->assertOk();
        $reponse->assertSee('PA-PIL');
        $reponse->assertSee('PB-PIL');
        // Programme actif SANS maquette active : jamais opérationnel.
        $reponse->assertSee('Non utilisable');
        $reponse->assertDontSee('bg-emerald-100');
        // Version courante + crédits attendus/observés.
        $reponse->assertSee('v1');
        $reponse->assertSee('180');
    }

    public function test_pilotage_filtres_combines(): void
    {
        $this->actingAs($this->scolarite)
            ->get(route('admin.referentiel.pilotage', ['search' => 'PA-PIL']))
            ->assertOk()->assertSee('PA-PIL')->assertDontSee('PB-PIL');

        $this->actingAs($this->scolarite)
            ->get(route('admin.referentiel.pilotage', ['maquette' => 'aucune']))
            ->assertOk()->assertSee('PB-PIL')->assertDontSee('PA-PIL');

        $this->actingAs($this->scolarite)
            ->get(route('admin.referentiel.pilotage', ['maquette' => 'attente']))
            ->assertOk()->assertSee('PA-PIL')->assertDontSee('PB-PIL');
    }

    public function test_pilotage_scope_facute_restreinte(): void
    {
        $reponse = $this->actingAs($this->adminFacA)->get(route('admin.referentiel.pilotage'));

        $reponse->assertOk();
        $reponse->assertSee('PA-PIL');
        $reponse->assertDontSee('PB-PIL');
    }

    public function test_pilotage_actions_contextuelles_selon_permissions(): void
    {
        // Scolarité (manage) : transition proposée sur le brouillon.
        $this->actingAs($this->scolarite)->get(route('admin.referentiel.pilotage'))
            ->assertOk()->assertSee('Soumettre en revue');

        // Admin faculté (view seul) : lecture + prévalidation, JAMAIS de transition.
        $reponse = $this->actingAs($this->adminFacA)->get(route('admin.referentiel.pilotage'));
        $reponse->assertOk();
        $reponse->assertDontSee('Soumettre en revue');
        $reponse->assertDontSee('Approuver');
        $reponse->assertDontSee('Activer (bascule atomique)');
        $reponse->assertSee('Prévalidation');
    }

    public function test_pilotage_transition_brouillon_vers_revue(): void
    {
        $this->actingAs($this->scolarite)->patch(
            route('admin.referentiel.maquettes.transition', $this->maquetteA), ['statut' => 'review']
        )->assertRedirect();

        $this->assertSame('review', $this->maquetteA->fresh()->statut);
    }

    public function test_pilotage_invite_redirige_connexion(): void
    {
        $this->get(route('admin.referentiel.pilotage'))->assertRedirect();
    }

    public function test_pilotage_necrit_rien(): void
    {
        $avant = [Programme::count(), Maquette::count(), UniteEnseignement::count()];

        $this->actingAs($this->scolarite)->get(route('admin.referentiel.pilotage'))->assertOk();

        $this->assertSame($avant, [Programme::count(), Maquette::count(), UniteEnseignement::count()]);
    }
}
