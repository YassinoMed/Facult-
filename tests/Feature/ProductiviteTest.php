<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Schedule\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Interactions productives (§12, §11, §10) : bulk sécurisé, inline
 * editing re-validé, palette permission-gatée.
 */
class ProductiviteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true,
        ]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.prod@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    private function etudiant(string $cin): Etudiant
    {
        return Etudiant::create([
            'cin' => $cin, 'nom' => 'Bulk', 'prenom' => 'Test',
            'email' => uniqid('b.', true).'@example.com',
            'sexe' => 'M', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2025-2026',
            'adresse' => '1 rue', 'ville' => 'Rabat',
            'code_postal' => '10000', 'annee_bac' => '2024',
            'password' => 'motdepasse1',
        ]);
    }

    // ── Bulk statut ───────────────────────────────────────────────

    public function test_bulk_valide_plusieurs_dossiers_en_transaction(): void
    {
        $a = $this->etudiant('BK100001');
        $b = $this->etudiant('BK100002');

        $this->actingAs($this->admin)->post(route('admin.etudiants.bulkStatut'), [
            'ids' => [$a->id, $b->id], 'statut' => 'valide', 'confirmation' => '1',
        ])->assertRedirect();

        $this->assertSame('valide', $a->fresh()->statut);
        $this->assertSame('valide', $b->fresh()->statut);
    }

    public function test_bulk_exige_confirmation(): void
    {
        $a = $this->etudiant('BK100003');

        $this->actingAs($this->admin)->post(route('admin.etudiants.bulkStatut'), [
            'ids' => [$a->id], 'statut' => 'valide',
        ])->assertSessionHasErrors('confirmation');

        $this->assertSame('en_attente', $a->fresh()->statut);
    }

    public function test_bulk_bloque_hors_perimetre_sans_ecriture_partielle(): void
    {
        $faculteB = Faculte::create(['nom' => 'FB', 'code' => 'FB', 'description' => 'B', 'active' => true]);
        $adminA = User::creerCompteBackOffice([
            'name' => 'Admin A', 'email' => 'aa.prod@universite.test',
            'password' => 'Motdepasse@123', 'faculte_id' => $this->faculte->id,
        ], 'admin_faculte');

        $dedans = $this->etudiant('BK100004');
        $dehors = Etudiant::create([
            'cin' => 'BK100005', 'nom' => 'Out', 'prenom' => 'Test',
            'email' => uniqid('o.', true).'@example.com',
            'sexe' => 'M', 'faculte_id' => $faculteB->id,
            'niveau' => 'L1', 'annee_academique' => '2025-2026',
            'adresse' => '1 rue', 'ville' => 'Rabat',
            'code_postal' => '10000', 'annee_bac' => '2024',
            'password' => 'motdepasse1',
        ]);

        $this->actingAs($adminA)->post(route('admin.etudiants.bulkStatut'), [
            'ids' => [$dedans->id, $dehors->id], 'statut' => 'valide', 'confirmation' => '1',
        ])->assertForbidden();

        // Rollback : même le dossier du périmètre est intact.
        $this->assertSame('en_attente', $dedans->fresh()->statut);
        $this->assertSame('en_attente', $dehors->fresh()->statut);
    }

    public function test_bulk_sans_permission_403(): void
    {
        $agent = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => 'ag.prod@universite.test',
            'password' => 'Motdepasse@123',
        ], 'consultation');
        $a = $this->etudiant('BK100006');

        $this->actingAs($agent)->post(route('admin.etudiants.bulkStatut'), [
            'ids' => [$a->id], 'statut' => 'valide', 'confirmation' => '1',
        ])->assertForbidden();
    }

    public function test_bulk_json_repond_compteur(): void
    {
        $a = $this->etudiant('BK100007');

        $reponse = $this->actingAs($this->admin)->postJson(route('admin.etudiants.bulkStatut'), [
            'ids' => [$a->id], 'statut' => 'rejete', 'confirmation' => '1',
        ])->assertOk();

        $this->assertSame(1, $reponse->json('count'));
    }

    // ── Inline editing salles ─────────────────────────────────────

    public function test_inline_capacite_json_ok(): void
    {
        $salle = Room::create(['code' => 'B12', 'nom' => 'Salle', 'capacite' => 40, 'actif' => true]);

        $reponse = $this->actingAs($this->admin)->patchJson(
            route('admin.salles.update', $salle),
            ['capacite' => 55]
        )->assertOk();

        $this->assertSame(55, $reponse->json('salle.capacite'));
        $this->assertSame(55, $salle->fresh()->capacite);
    }

    public function test_inline_capacite_invalide_422_valeur_restauree(): void
    {
        $salle = Room::create(['code' => 'B13', 'nom' => 'Salle', 'capacite' => 40, 'actif' => true]);

        $this->actingAs($this->admin)->patchJson(
            route('admin.salles.update', $salle),
            ['capacite' => -5]
        )->assertUnprocessable();

        $this->assertSame(40, $salle->fresh()->capacite);
    }

    public function test_inline_sans_permission_403(): void
    {
        $agent = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => 'ag2.prod@universite.test',
            'password' => 'Motdepasse@123',
        ], 'agent_scolarite');
        $salle = Room::create(['code' => 'B14', 'nom' => 'Salle', 'capacite' => 40, 'actif' => true]);

        $this->actingAs($agent)->patchJson(
            route('admin.salles.update', $salle),
            ['capacite' => 60]
        )->assertForbidden();
    }

    // ── Palette de commandes ──────────────────────────────────────

    public function test_palette_admin_voit_actions_admin(): void
    {
        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('paletteCommandes', false)
            ->assertSee('Importer des données', false);
    }

    public function test_palette_etudiant_sans_actions_admin(): void
    {
        $etudiant = $this->etudiant('BK100008');
        session([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_auth_prenom' => $etudiant->prenom,
            'etudiant_auth_nom' => $etudiant->nom,
        ]);

        $this->get(route('etudiant.dashboard'))
            ->assertOk()
            ->assertSee('paletteCommandes', false)
            ->assertSee('Mes notes', false)
            ->assertDontSee('Importer des données');
    }
}
