<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\RegistrationPeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests CRUD Périodes d'inscription (admin/periodes).
 */
class AdminPeriodeInscriptionCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $utilisateurSansDroit;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences de Tunis',
            'code' => 'FST',
            'description' => 'FST Campus',
            'active' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        // Compte admin habilité avec 'configuration.manage'
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin Config',
            'email' => 'admin.config@univ.tn',
            'password' => 'Secret123456!',
        ], 'admin_scolarite');

        // Compte sans la permission 'configuration.manage'
        $this->utilisateurSansDroit = User::creerCompteBackOffice([
            'name' => 'Consultant',
            'email' => 'consultant@univ.tn',
            'password' => 'Secret123456!',
        ], 'consultation');
    }

    public function test_index_affiche_la_liste_des_periodes_et_les_statistiques(): void
    {
        RegistrationPeriod::create([
            'nom' => 'Rentrée principale',
            'annee_universitaire_id' => $this->annee->id,
            'type_periode' => 'normale',
            'opened_at' => now()->subDay(),
            'closed_at' => now()->addDays(10),
            'statut' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $reponse = $this->actingAs($this->admin)->get(route('admin.periodes.index'));

        $reponse->assertOk();
        $reponse->assertViewIs('admin.periodes.index');
        $reponse->assertSee('Périodes d’inscription');
        $reponse->assertSee('Rentrée principale');
        $reponse->assertSee('Ouvertes en cours');
    }

    public function test_create_affiche_le_formulaire_de_creation(): void
    {
        $reponse = $this->actingAs($this->admin)->get(route('admin.periodes.create'));

        $reponse->assertOk();
        $reponse->assertViewIs('admin.periodes.create');
        $reponse->assertSee('Créer une période d’inscription');
        $reponse->assertSee('Type de période');
    }

    public function test_store_cree_une_periode_normale(): void
    {
        $reponse = $this->actingAs($this->admin)->post(route('admin.periodes.store'), [
            'annee_universitaire_id' => $this->annee->id,
            'type_periode' => 'normale',
            'nom' => 'Session Automne',
            'faculte_id' => $this->faculte->id,
            'opened_at' => '2026-09-01T08:00',
            'closed_at' => '2026-09-30T18:00',
        ]);

        $reponse->assertRedirect(route('admin.periodes.index'));
        $reponse->assertSessionHas('success');

        $this->assertDatabaseHas('registration_periods', [
            'nom' => 'Session Automne',
            'type_periode' => 'normale',
            'annee_universitaire_id' => $this->annee->id,
            'statut' => 'active',
        ]);
    }

    public function test_store_cree_une_periode_exception_avec_motif(): void
    {
        $reponse = $this->actingAs($this->admin)->post(route('admin.periodes.store'), [
            'annee_universitaire_id' => $this->annee->id,
            'type_periode' => 'exception',
            'nom' => 'Dérogation retardataires',
            'faculte_id' => $this->faculte->id,
            'opened_at' => '2026-10-01T08:00',
            'closed_at' => '2026-10-05T18:00',
            'motif' => 'Dérogation accordée suite aux retards de délivrance des attestations officielles.',
        ]);

        $reponse->assertRedirect(route('admin.periodes.index'));
        $reponse->assertSessionHas('success');

        $this->assertDatabaseHas('registration_periods', [
            'nom' => 'Dérogation retardataires',
            'type_periode' => 'exception',
            'motif' => 'Dérogation accordée suite aux retards de délivrance des attestations officielles.',
        ]);
    }

    public function test_store_refuse_une_exception_sans_motif(): void
    {
        $reponse = $this->actingAs($this->admin)->post(route('admin.periodes.store'), [
            'annee_universitaire_id' => $this->annee->id,
            'type_periode' => 'exception',
            'nom' => 'Dérogation sans motif',
            'opened_at' => '2026-10-01T08:00',
            'closed_at' => '2026-10-05T18:00',
            'motif' => '',
        ]);

        $reponse->assertSessionHasErrors(['motif']);
        $this->assertSame(0, RegistrationPeriod::where('type_periode', 'exception')->count());
    }

    public function test_show_affiche_les_details_de_la_periode(): void
    {
        $periode = RegistrationPeriod::create([
            'nom' => 'Consultation Période',
            'annee_universitaire_id' => $this->annee->id,
            'type_periode' => 'normale',
            'faculte_id' => $this->faculte->id,
            'opened_at' => '2026-09-01 08:00:00',
            'closed_at' => '2026-09-20 18:00:00',
            'statut' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $reponse = $this->actingAs($this->admin)->get(route('admin.periodes.show', $periode));

        $reponse->assertOk();
        $reponse->assertViewIs('admin.periodes.show');
        $reponse->assertSee('Consultation Période');
        $reponse->assertSee('Calendrier d’ouverture');
        $reponse->assertSee('Faculté des Sciences de Tunis');
    }

    public function test_edit_affiche_le_formulaire_d_edition(): void
    {
        $periode = RegistrationPeriod::create([
            'nom' => 'Période à modifier',
            'annee_universitaire_id' => $this->annee->id,
            'type_periode' => 'normale',
            'opened_at' => '2026-09-01 08:00:00',
            'closed_at' => '2026-09-20 18:00:00',
            'statut' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $reponse = $this->actingAs($this->admin)->get(route('admin.periodes.edit', $periode));

        $reponse->assertOk();
        $reponse->assertViewIs('admin.periodes.edit');
        $reponse->assertSee('Période à modifier');
    }

    public function test_update_met_a_jour_une_periode(): void
    {
        $periode = RegistrationPeriod::create([
            'nom' => 'Ancien nom',
            'annee_universitaire_id' => $this->annee->id,
            'type_periode' => 'normale',
            'opened_at' => '2026-09-01 08:00:00',
            'closed_at' => '2026-09-20 18:00:00',
            'statut' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $reponse = $this->actingAs($this->admin)->put(route('admin.periodes.update', $periode), [
            'annee_universitaire_id' => $this->annee->id,
            'type_periode' => 'normale',
            'nom' => 'Nouveau libellé mis à jour',
            'faculte_id' => $this->faculte->id,
            'opened_at' => '2026-09-05T08:00',
            'closed_at' => '2026-09-25T18:00',
        ]);

        $reponse->assertRedirect(route('admin.periodes.show', $periode));
        $reponse->assertSessionHas('success');

        $periode->refresh();
        $this->assertSame('Nouveau libellé mis à jour', $periode->nom);
        $this->assertSame($this->faculte->id, $periode->faculte_id);
    }

    public function test_destroy_supprime_une_periode(): void
    {
        $periode = RegistrationPeriod::create([
            'nom' => 'Période à supprimer',
            'annee_universitaire_id' => $this->annee->id,
            'type_periode' => 'normale',
            'opened_at' => '2026-09-01 08:00:00',
            'closed_at' => '2026-09-20 18:00:00',
            'statut' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $reponse = $this->actingAs($this->admin)->delete(route('admin.periodes.destroy', $periode));

        $reponse->assertRedirect(route('admin.periodes.index'));
        $reponse->assertSessionHas('success');

        $this->assertDatabaseMissing('registration_periods', ['id' => $periode->id]);
    }

    public function test_prolonger_etend_la_date_de_fermeture(): void
    {
        $periode = RegistrationPeriod::create([
            'nom' => 'Période à prolonger',
            'annee_universitaire_id' => $this->annee->id,
            'type_periode' => 'normale',
            'opened_at' => now()->subDays(5),
            'closed_at' => now()->addDays(5),
            'statut' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $nouvelleDate = now()->addDays(20);

        $reponse = $this->actingAs($this->admin)->post(route('admin.periodes.prolonger', $periode), [
            'closed_at' => $nouvelleDate->format('Y-m-d H:i:s'),
            'motif' => 'Prolongation officielle suite aux incidents de plateforme.',
        ]);

        $reponse->assertRedirect();
        $reponse->assertSessionHas('success');

        $periode->refresh();
        $this->assertTrue($periode->closed_at->greaterThan(now()->addDays(15)));
    }

    public function test_revoquer_annule_la_periode(): void
    {
        $periode = RegistrationPeriod::create([
            'nom' => 'Période à révoquer',
            'annee_universitaire_id' => $this->annee->id,
            'type_periode' => 'normale',
            'opened_at' => '2026-09-01 08:00:00',
            'closed_at' => '2026-09-20 18:00:00',
            'statut' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $reponse = $this->actingAs($this->admin)->post(route('admin.periodes.revoquer', $periode), [
            'motif' => 'Révocation immédiate ordonnée par la scolarité centrale.',
        ]);

        $reponse->assertRedirect();
        $reponse->assertSessionHas('success');

        $periode->refresh();
        $this->assertSame('revoquee', $periode->statut);
    }

    public function test_acces_refuse_sans_permission_configuration_manage(): void
    {
        $reponse = $this->actingAs($this->utilisateurSansDroit)->get(route('admin.periodes.index'));
        $reponse->assertForbidden();

        $reponseCreate = $this->actingAs($this->utilisateurSansDroit)->get(route('admin.periodes.create'));
        $reponseCreate->assertForbidden();
    }
}
