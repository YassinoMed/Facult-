<?php

namespace Tests\Feature;

use App\Models\Faculte;
use App\Models\User;
use App\Modules\Attendance\Models\AttendanceTemplate;
use Database\Seeders\AttendanceTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests des modèles de fiches de présence (§10-12, §27-30) :
 * CRUD, protection des modèles système, duplication, validation serveur et audit.
 */
class AttendanceTemplateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $agent;

    private Faculte $faculte;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.attendance.active' => true]);

        $this->faculte = Faculte::create([
            'nom' => 'FST Test',
            'code' => 'FST',
            'description' => 'Test',
            'active' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin Scolarite',
            'email' => 'admin.template@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculte->id,
        ], 'admin_scolarite');

        $this->agent = User::creerCompteBackOffice([
            'name' => 'Agent Scolarite',
            'email' => 'agent.template@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculte->id,
        ], 'agent_scolarite');

        $this->seed(AttendanceTemplateSeeder::class);
    }

    public function test_liste_modeles_accessible_admin(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.presences.modeles.index'));

        $response->assertOk()
            ->assertSee('Fiche classique')
            ->assertSee('Feuille d\'émargement')
            ->assertSee('Fiche détaillée')
            ->assertSee('Fiche Travaux Dirigés (TD)')
            ->assertSee('Fiche Travaux Pratiques (TP)')
            ->assertSee('Feuille d\'émargement d\'examen')
            ->assertSee('Fiche double signature')
            ->assertSee('Fiche présence avec QR Code');
    }

    public function test_creation_modele_personnalise_valide(): void
    {
        $payload = [
            'name' => 'Gabarit Master Spécial',
            'code' => 'custom_m2_special',
            'description' => 'Fiche spécifique pour les séminaires Master 2',
            'type' => 'custom',
            'is_active' => true,
            'configuration' => [
                'orientation' => 'landscape',
                'paper' => 'A4',
                'columns' => [
                    ['key' => 'numero', 'label' => 'N°', 'width' => '5%', 'visible' => true],
                    ['key' => 'matricule', 'label' => 'Matricule', 'width' => '15%', 'visible' => true],
                    ['key' => 'nom', 'label' => 'Nom', 'width' => '25%', 'visible' => true],
                    ['key' => 'prenom', 'label' => 'Prénom', 'width' => '25%', 'visible' => true],
                    ['key' => 'signature', 'label' => 'Émargement', 'width' => '30%', 'visible' => true],
                ],
            ],
        ];

        $response = $this->actingAs($this->admin)->post(route('admin.presences.modeles.store'), $payload);

        $response->assertRedirect(route('admin.presences.modeles.index'));
        $this->assertDatabaseHas('attendance_templates', [
            'code' => 'custom_m2_special',
            'name' => 'Gabarit Master Spécial',
            'is_system' => false,
        ]);

        $this->assertDatabaseHas('integration_logs', [
            'integration' => 'attendance',
            'operation' => 'ATTENDANCE_TEMPLATE_CREATED',
            'status' => 'completed',
        ]);
    }

    public function test_rejet_configuration_invalide(): void
    {
        $payloadInvalide = [
            'name' => 'Modèle Invalide',
            'type' => 'custom',
            'configuration' => [
                'orientation' => 'diagonal', // Invalide
                'columns' => [
                    ['key' => 'colonne_inexistante', 'label' => 'Test', 'visible' => true], // Clé invalide
                ],
            ],
        ];

        $response = $this->actingAs($this->admin)->post(route('admin.presences.modeles.store'), $payloadInvalide);

        $response->assertSessionHasErrors(['configuration.orientation', 'configuration.columns.0.key']);
    }

    public function test_duplication_modele_existant(): void
    {
        $modeleSource = AttendanceTemplate::where('code', 'exam')->firstOrFail();

        $response = $this->actingAs($this->admin)->post(
            route('admin.presences.modeles.duplicate', $modeleSource),
            ['nom' => 'Examen Rattrapage Spécial']
        );

        $response->assertRedirect(route('admin.presences.modeles.index'));
        $this->assertDatabaseHas('attendance_templates', [
            'name' => 'Examen Rattrapage Spécial',
            'is_system' => false,
        ]);
    }

    public function test_protection_modeles_systeme_contre_suppression(): void
    {
        $modeleSysteme = AttendanceTemplate::where('code', 'classic')->firstOrFail();
        $this->assertTrue($modeleSysteme->is_system);

        $response = $this->actingAs($this->admin)->delete(route('admin.presences.modeles.destroy', $modeleSysteme));

        // Refus de suppression (403 Forbidden)
        $response->assertForbidden();
        $this->assertDatabaseHas('attendance_templates', ['code' => 'classic']);
    }

    public function test_suppression_modele_personnalise_autorisee(): void
    {
        $modeleCustom = AttendanceTemplate::create([
            'name' => 'Modèle Éphémère',
            'code' => 'custom_ephemere',
            'type' => 'custom',
            'is_system' => false,
            'is_active' => true,
            'configuration' => [
                'orientation' => 'portrait',
                'paper' => 'A4',
                'columns' => [['key' => 'nom', 'label' => 'Nom', 'visible' => true]],
            ],
        ]);

        $response = $this->actingAs($this->admin)->delete(route('admin.presences.modeles.destroy', $modeleCustom));

        $response->assertRedirect(route('admin.presences.modeles.index'));
        $this->assertDatabaseMissing('attendance_templates', ['id' => $modeleCustom->id]);

        $this->assertDatabaseHas('integration_logs', [
            'integration' => 'attendance',
            'operation' => 'ATTENDANCE_TEMPLATE_DELETED',
            'status' => 'completed',
        ]);
    }

    public function test_toggle_statut_modele(): void
    {
        $modele = AttendanceTemplate::where('code', 'tp')->firstOrFail();
        $this->assertTrue($modele->is_active);

        $this->actingAs($this->admin)->patch(route('admin.presences.modeles.toggle', $modele));
        $this->assertFalse($modele->fresh()->is_active);

        $this->actingAs($this->admin)->patch(route('admin.presences.modeles.toggle', $modele));
        $this->assertTrue($modele->fresh()->is_active);
    }

    public function test_agent_sans_permission_manage_ne_peut_pas_creer(): void
    {
        $response = $this->actingAs($this->agent)->get(route('admin.presences.modeles.create'));
        $response->assertForbidden();
    }
}
