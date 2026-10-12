<?php

namespace Tests\Feature;

use App\Models\Faculte;
use App\Models\User;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B — Périmètre faculté (modèle salles) + cohérence actif/statut.
 * Décisions : isolation modèle salles, `actif` fait foi.
 */
class EnseignantPerimetreTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private Departement $departementA;

    private Departement $departementB;

    private User $adminFaculteA;

    private User $scolarite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculteA = Faculte::create(['nom' => 'FA', 'code' => 'FA', 'description' => 'T', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'FB', 'code' => 'FB', 'description' => 'T', 'active' => true]);
        $this->departementA = Departement::create([
            'code' => 'DEP-A', 'nom' => 'Département A', 'faculte_id' => $this->faculteA->id,
        ]);
        $this->departementB = Departement::create([
            'code' => 'DEP-B', 'nom' => 'Département B', 'faculte_id' => $this->faculteB->id,
        ]);
        $this->adminFaculteA = User::creerCompteBackOffice([
            'name' => 'Admin FA', 'email' => 'admin.fa@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');
        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.peri@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    private function creerEnseignant(string $suffixe, ?int $departementId): Teacher
    {
        return Teacher::create([
            'nom' => 'Nom'.$suffixe, 'prenom' => 'Prenom'.$suffixe,
            'email' => "peri{$suffixe}@universite.test",
            'matricule' => 'PERI-'.$suffixe,
            'departement_id' => $departementId,
            'actif' => true, 'statut' => 'actif',
        ]);
    }

    public function test_index_restreint_a_sa_faculte_plus_transversaux(): void
    {
        $this->creerEnseignant('A', $this->departementA->id);
        $this->creerEnseignant('B', $this->departementB->id);
        $this->creerEnseignant('T', null);

        $reponse = $this->actingAs($this->adminFaculteA)->get(route('admin.enseignants.index'));

        $reponse->assertOk();
        $reponse->assertSee('NomA');
        $reponse->assertSee('NomT');
        $reponse->assertDontSee('NomB');

        // Rôle global : tout reste visible.
        $global = $this->actingAs($this->scolarite)->get(route('admin.enseignants.index'));
        $global->assertOk()->assertSee('NomB');
    }

    public function test_acces_direct_hors_perimetre_404(): void
    {
        $enseignantB = $this->creerEnseignant('B', $this->departementB->id);

        $this->actingAs($this->adminFaculteA)->get(route('admin.enseignants.show', $enseignantB))
            ->assertNotFound();
        $this->actingAs($this->adminFaculteA)->get(route('admin.enseignants.edit', $enseignantB))
            ->assertNotFound();
        $this->actingAs($this->adminFaculteA)->put(route('admin.enseignants.update', $enseignantB), [
            'nom' => 'Pirate', 'prenom' => 'X',
        ])->assertNotFound();
        $this->actingAs($this->adminFaculteA)->delete(route('admin.enseignants.destroy', $enseignantB))
            ->assertNotFound();

        // Rien n'a été touché.
        $this->assertSame('NomB', $enseignantB->fresh()->nom);
        $this->assertDatabaseHas('teachers', ['id' => $enseignantB->id]);
    }

    public function test_creation_refusee_vers_departement_etranger(): void
    {
        // Département étranger → 404, rien créé.
        $this->actingAs($this->adminFaculteA)->post(route('admin.enseignants.store'), [
            'nom' => 'Pirate', 'prenom' => 'X', 'departement_id' => $this->departementB->id,
        ])->assertNotFound();
        $this->assertDatabaseMissing('teachers', ['nom' => 'Pirate']);

        // Son département → créé. Sans département → transversal créé.
        $this->actingAs($this->adminFaculteA)->post(route('admin.enseignants.store'), [
            'nom' => 'Loyal', 'prenom' => 'Y', 'departement_id' => $this->departementA->id,
        ])->assertRedirect();
        $this->actingAs($this->adminFaculteA)->post(route('admin.enseignants.store'), [
            'nom' => 'Transverse', 'prenom' => 'Z',
        ])->assertRedirect();
        $this->assertDatabaseHas('teachers', ['nom' => 'Loyal', 'departement_id' => $this->departementA->id]);
        $this->assertDatabaseHas('teachers', ['nom' => 'Transverse', 'departement_id' => null]);
    }

    public function test_rattachement_etranger_refuse_en_modification(): void
    {
        $enseignant = $this->creerEnseignant('A', $this->departementA->id);

        $this->actingAs($this->adminFaculteA)->put(route('admin.enseignants.update', $enseignant), [
            'nom' => 'NomA', 'prenom' => 'PrenomA', 'departement_id' => $this->departementB->id,
        ])->assertNotFound();

        $this->assertSame($this->departementA->id, (int) $enseignant->fresh()->departement_id);
    }

    public function test_formulaire_propose_uniquement_ses_departements(): void
    {
        $reponse = $this->actingAs($this->adminFaculteA)->get(route('admin.enseignants.create'));

        $reponse->assertOk()->assertSee('DEP-A')->assertDontSee('DEP-B');
    }

    public function test_statut_synchronise_avec_actif(): void
    {
        // Création avec statut suspendu → compte inactif, connexion refusée.
        $this->actingAs($this->scolarite)->post(route('admin.enseignants.store'), [
            'nom' => 'Susp', 'prenom' => 'S', 'email' => 'susp@universite.test',
            'statut' => 'suspendu', 'password' => 'Initial@12345', 'password_confirmation' => 'Initial@12345',
        ])->assertRedirect();
        $enseignant = Teacher::where('email', 'susp@universite.test')->firstOrFail();
        $this->assertFalse($enseignant->actif);
        $this->assertSame('suspendu', $enseignant->statut);
        $this->post(route('enseignant.connexion.login'), [
            'identifiant' => 'susp@universite.test', 'password' => 'Initial@12345',
        ])->assertSessionHasErrors('identifiant');

        // Repasse à actif → compte réactivé.
        $this->actingAs($this->scolarite)->put(route('admin.enseignants.update', $enseignant), [
            'nom' => 'Susp', 'prenom' => 'S', 'statut' => 'actif',
        ])->assertRedirect();
        $enseignant->refresh();
        $this->assertTrue($enseignant->actif);
        $this->assertSame('actif', $enseignant->statut);

        // Chemin historique (actif seul, sans statut) : statut suit.
        $this->actingAs($this->scolarite)->put(route('admin.enseignants.update', $enseignant), [
            'nom' => 'Susp', 'prenom' => 'S', 'actif' => '0',
        ])->assertRedirect();
        $enseignant->refresh();
        $this->assertFalse($enseignant->actif);
        $this->assertSame('inactif', $enseignant->statut);
    }
}
