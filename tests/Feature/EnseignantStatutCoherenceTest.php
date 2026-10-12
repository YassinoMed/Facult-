<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B — Cohérence métier : `actif` est la projection de `statut`
 * (invariant attendu par `AffectationEnseignementService:32` — double
 * condition actif && statut==='actif'). Un seul interrupteur métier :
 * le statut administratif. Jamais de divergence entre formulaire,
 * base, import et connexion.
 */
class EnseignantStatutCoherenceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.statut@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    public function test_creation_avec_statut_suspendu_desactive_le_compte(): void
    {
        $this->actingAs($this->admin)->post(route('admin.enseignants.store'), [
            'nom' => 'Suspendu', 'prenom' => 'Test', 'email' => 'susp@universite.test',
            'statut' => 'suspendu',
        ])->assertRedirect();

        $enseignant = Teacher::where('email', 'susp@universite.test')->firstOrFail();

        $this->assertSame('suspendu', $enseignant->statut);
        $this->assertFalse($enseignant->actif);
    }

    public function test_creation_sans_statut_vaut_actif(): void
    {
        $this->actingAs($this->admin)->post(route('admin.enseignants.store'), [
            'nom' => 'Standard', 'prenom' => 'Test', 'email' => 'std@universite.test',
        ])->assertRedirect();

        $enseignant = Teacher::where('email', 'std@universite.test')->firstOrFail();

        $this->assertSame('actif', $enseignant->statut);
        $this->assertTrue($enseignant->actif);
    }

    public function test_mise_retraite_desactive_le_compte(): void
    {
        // `actif` explicite : les instances en mémoire n'ont pas la valeur
        // par défaut DB appliquée avant relecture (convention FiabiliteTest).
        $enseignant = Teacher::create(['nom' => 'Aine', 'prenom' => 'Test', 'email' => 'aine@universite.test', 'statut' => 'actif', 'actif' => true]);
        $this->assertTrue($enseignant->actif);

        $this->actingAs($this->admin)->put(route('admin.enseignants.update', $enseignant), [
            'nom' => 'Aine', 'prenom' => 'Test', 'email' => 'aine@universite.test', 'statut' => 'retraite',
        ])->assertRedirect();

        $enseignant->refresh();

        $this->assertSame('retraite', $enseignant->statut);
        $this->assertFalse($enseignant->actif, 'Un enseignant retraité ne doit plus accéder au portail.');
    }

    public function test_retour_a_actif_reactive_le_compte(): void
    {
        $enseignant = Teacher::create(['nom' => 'Retour', 'prenom' => 'Test', 'email' => 'retour@universite.test', 'statut' => 'retraite', 'actif' => false]);
        $this->assertFalse($enseignant->actif);

        $this->actingAs($this->admin)->put(route('admin.enseignants.update', $enseignant), [
            'nom' => 'Retour', 'prenom' => 'Test', 'email' => 'retour@universite.test', 'statut' => 'actif',
        ])->assertRedirect();

        $enseignant->refresh();

        $this->assertSame('actif', $enseignant->statut);
        $this->assertTrue($enseignant->actif);
    }

    public function test_mise_a_jour_sans_statut_ne_lechange_pas(): void
    {
        $enseignant = Teacher::create(['nom' => 'Stable', 'prenom' => 'Test', 'email' => 'stable@universite.test', 'statut' => 'suspendu', 'actif' => false]);
        $this->assertFalse($enseignant->actif);

        $this->actingAs($this->admin)->put(route('admin.enseignants.update', $enseignant), [
            'nom' => 'Stable', 'prenom' => 'Test', 'email' => 'stable@universite.test', 'bureau' => 'B-999',
        ])->assertRedirect();

        $enseignant->refresh();

        $this->assertSame('suspendu', $enseignant->statut, 'Un update sans statut ne doit jamais le réinitialiser.');
        $this->assertSame('B-999', $enseignant->bureau);
        $this->assertFalse($enseignant->actif);
    }

    public function test_formulaire_propose_le_statut_et_plus_la_case_actif(): void
    {
        $enseignant = Teacher::create(['nom' => 'Vue', 'prenom' => 'Test', 'email' => 'vue@universite.test', 'statut' => 'suspendu']);

        $creation = $this->actingAs($this->admin)->get(route('admin.enseignants.create'));
        $creation->assertOk();
        $creation->assertSee('name="statut"', false);
        $creation->assertDontSee('name="actif"', false);

        $edition = $this->actingAs($this->admin)->get(route('admin.enseignants.edit', $enseignant));
        $edition->assertOk();
        $edition->assertSee('name="statut"', false);
        $edition->assertDontSee('name="actif"', false);
    }

    public function test_liste_affiche_le_statut_reel(): void
    {
        Teacher::create(['nom' => 'Zuspendu', 'prenom' => 'Liste', 'email' => 'zsusp@universite.test', 'statut' => 'suspendu']);

        $this->actingAs($this->admin)->get(route('admin.enseignants.index'))
            ->assertOk()
            ->assertSee('Suspendu');
    }
}
