<?php

namespace Tests\Feature;

use App\Livewire\AttendanceTemplateBuilder;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Attendance\Models\AttendanceTemplate;
use App\Modules\Attendance\Services\AttendanceTemplateRenderer;
use Database\Seeders\AttendanceTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tests du cockpit builder des modèles de présence (§10-12, §27-30) :
 * Livewire, Alpine, Drag & Drop, validation JSON, isolation faculté, XSS, PDF et non-PII.
 */
class AttendanceTemplateBuilderTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $agent;

    private User $adminAutreFaculte;

    private Faculte $faculteA;

    private Faculte $faculteB;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.attendance.active' => true]);

        $this->faculteA = Faculte::create([
            'nom' => 'Faculté des Sciences de Tunis',
            'code' => 'FST',
            'description' => 'Campus principal',
            'active' => true,
        ]);

        $this->faculteB = Faculte::create([
            'nom' => 'Faculté de Médecine',
            'code' => 'FMT',
            'description' => 'Campus santé',
            'active' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin Scolarite FST',
            'email' => 'admin.fst@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_scolarite');

        $this->agent = User::creerCompteBackOffice([
            'name' => 'Agent Scolarite FST',
            'email' => 'agent.fst@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'agent_scolarite');

        $this->adminAutreFaculte = User::creerCompteBackOffice([
            'name' => 'Admin Scolarite FMT',
            'email' => 'admin.fmt@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_scolarite');

        $this->seed(AttendanceTemplateSeeder::class);
    }

    public function test_cockpit_accessible_avec_permission_et_liste_8_modeles(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.presences.modeles.index'));

        $response->assertOk()
            ->assertSee('Cockpit des modèles de présence')
            ->assertSee('Fiche classique')
            ->assertSee('Feuille d\'émargement')
            ->assertSee('Fiche détaillée')
            ->assertSee('Fiche Travaux Dirigés (TD)')
            ->assertSee('Fiche Travaux Pratiques (TP)')
            ->assertSee('Feuille d\'émargement d\'examen')
            ->assertSee('Fiche double signature')
            ->assertSee('Fiche présence avec QR Code')
            ->assertSee('Paramètres généraux du document')
            ->assertSee('Blocs du document')
            ->assertSee('Configuration du bloc');
    }

    public function test_creation_modele_personnalise_via_livewire_builder(): void
    {
        $composant = Livewire::actingAs($this->admin)
            ->test(AttendanceTemplateBuilder::class)
            ->call('createNewTemplate')
            ->set('name', 'Feuille Spéciale Master IA')
            ->set('code', 'custom_master_ia')
            ->set('description', 'Fiche dédiée aux séminaires')
            ->set('orientation', 'landscape')
            ->set('paper', 'A4')
            ->call('save');

        $composant->assertHasNoErrors();

        $this->assertDatabaseHas('attendance_templates', [
            'code' => 'custom_master_ia',
            'name' => 'Feuille Spéciale Master IA',
            'type' => 'custom',
            'is_system' => false,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('integration_logs', [
            'integration' => 'attendance',
            'operation' => 'ATTENDANCE_TEMPLATE_CREATED',
            'external_reference' => 'TMPL-custom_master_ia',
        ]);
    }

    public function test_reorganisation_et_deplacement_des_blocs(): void
    {
        $template = AttendanceTemplate::where('code', 'classic')->firstOrFail();

        $composant = Livewire::actingAs($this->admin)
            ->test(AttendanceTemplateBuilder::class, ['initialTemplateId' => $template->id]);

        $blocsInitiaux = $composant->get('blocks');
        $this->assertNotEmpty($blocsInitiaux);
        $premierType = $blocsInitiaux[0]['type'];

        // Déplacer le premier bloc en position 2
        $composant->call('moveBlock', 0, 1);

        $blocsApres = $composant->get('blocks');
        $this->assertEquals($premierType, $blocsApres[1]['type']);
        $this->assertEquals(2, $blocsApres[1]['position']);
    }

    public function test_modification_option_bloc_et_sauvegarde(): void
    {
        $template = AttendanceTemplate::create([
            'name' => 'Gabarit Test Options',
            'code' => 'test_options_bloc',
            'type' => 'custom',
            'is_system' => false,
            'is_active' => true,
            'configuration' => [
                'orientation' => 'portrait',
                'paper' => 'A4',
                'blocks' => (new AttendanceTemplate(['type' => 'custom']))->blocsParDefaut(),
                'columns' => AttendanceTemplate::COLONNES_PAR_DEFAUT,
            ],
            'created_by' => $this->admin->id,
        ]);

        $composant = Livewire::actingAs($this->admin)
            ->test(AttendanceTemplateBuilder::class, ['initialTemplateId' => $template->id]);

        // Sélectionner le bloc student_table (index 2 dans blocs par défaut)
        $composant->call('selectBlock', 2)
            ->call('updateBlockOption', 'font_size', '10pt')
            ->call('updateBlockOption', 'show_cin', true)
            ->call('save');

        $template->refresh();
        $blocks = $template->configuration['blocks'];
        $tableBlock = collect($blocks)->firstWhere('type', 'student_table');

        $this->assertNotNull($tableBlock);
        $this->assertEquals('10pt', $tableBlock['config']['font_size']);
        $this->assertTrue($tableBlock['config']['show_cin']);
    }

    public function test_duplication_modele_cree_copie_non_systeme(): void
    {
        $template = AttendanceTemplate::where('code', 'classic')->firstOrFail();

        Livewire::actingAs($this->admin)
            ->test(AttendanceTemplateBuilder::class, ['initialTemplateId' => $template->id])
            ->call('duplicate');

        $this->assertDatabaseHas('attendance_templates', [
            'name' => 'Copie de Fiche classique',
            'is_system' => false,
        ]);

        $this->assertDatabaseHas('integration_logs', [
            'integration' => 'attendance',
            'operation' => 'ATTENDANCE_TEMPLATE_DUPLICATED',
        ]);
    }

    public function test_bascule_actif_inactif(): void
    {
        $template = AttendanceTemplate::where('code', 'classic')->firstOrFail();
        $this->assertTrue($template->is_active);

        Livewire::actingAs($this->admin)
            ->test(AttendanceTemplateBuilder::class, ['initialTemplateId' => $template->id])
            ->call('toggleActive');

        $template->refresh();
        $this->assertFalse($template->is_active);

        $this->assertDatabaseHas('integration_logs', [
            'integration' => 'attendance',
            'operation' => 'ATTENDANCE_TEMPLATE_DISABLED',
        ]);
    }

    public function test_protection_absolue_des_modeles_systeme_contre_suppression(): void
    {
        $template = AttendanceTemplate::where('code', 'classic')->firstOrFail();
        $this->assertTrue($template->is_system);

        $composant = Livewire::actingAs($this->admin)
            ->test(AttendanceTemplateBuilder::class, ['initialTemplateId' => $template->id])
            ->call('deleteTemplate');

        $composant->assertSet('errorMessage', 'Les modèles système sont protégés et ne peuvent pas être supprimés.');
        $this->assertDatabaseHas('attendance_templates', ['code' => 'classic']);
    }

    public function test_suppression_autorisee_sur_modele_personnalise(): void
    {
        $template = AttendanceTemplate::create([
            'name' => 'Modèle Jetable',
            'code' => 'custom_jetable',
            'type' => 'custom',
            'is_system' => false,
            'is_active' => true,
            'configuration' => [
                'orientation' => 'portrait',
                'paper' => 'A4',
                'blocks' => (new AttendanceTemplate(['type' => 'custom']))->blocsParDefaut(),
            ],
            'created_by' => $this->admin->id,
        ]);

        Livewire::actingAs($this->admin)
            ->test(AttendanceTemplateBuilder::class, ['initialTemplateId' => $template->id])
            ->call('deleteTemplate');

        $this->assertDatabaseMissing('attendance_templates', ['code' => 'custom_jetable']);

        $this->assertDatabaseHas('integration_logs', [
            'integration' => 'attendance',
            'operation' => 'ATTENDANCE_TEMPLATE_DELETED',
            'external_reference' => 'TMPL-custom_jetable',
        ]);
    }

    public function test_validation_stricte_type_bloc_non_autorise(): void
    {
        $composant = Livewire::actingAs($this->admin)
            ->test(AttendanceTemplateBuilder::class)
            ->call('createNewTemplate')
            ->set('name', 'Modèle Invalide')
            ->set('code', 'custom_invalide')
            ->set('blocks', [
                ['type' => 'injection_dangereuse', 'position' => 1, 'enabled' => true, 'config' => []],
            ])
            ->call('save');

        $composant->assertSet('errorMessage', fn ($val) => str_contains((string) $val, 'Erreur de configuration des blocs'));
        $this->assertDatabaseMissing('attendance_templates', ['code' => 'custom_invalide']);
    }

    public function test_nettoyage_xss_dans_les_champs_texte(): void
    {
        Livewire::actingAs($this->admin)
            ->test(AttendanceTemplateBuilder::class)
            ->call('createNewTemplate')
            ->set('name', '<script>alert("xss")</script>Nom Nettoyé')
            ->set('code', 'custom_anti_xss')
            ->set('description', '<b>Description</b><img src=x onerror=alert(1)>')
            ->call('save');

        $template = AttendanceTemplate::where('code', 'custom_anti_xss')->firstOrFail();
        $this->assertStringNotContainsString('<script>', $template->name);
        $this->assertStringNotContainsString('onerror', $template->description);
        $this->assertEquals('alert("xss")Nom Nettoyé', $template->name);
    }

    public function test_isolation_faculte_stricte_retourne_404(): void
    {
        $adminFaculteA = User::creerCompteBackOffice([
            'name' => 'Admin Faculte A',
            'email' => 'admin.fac.a@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $adminFaculteB = User::creerCompteBackOffice([
            'name' => 'Admin Faculte B',
            'email' => 'admin.fac.b@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');

        // Modèle restreint à la faculté B (Médecine)
        $templateFaculteB = AttendanceTemplate::create([
            'name' => 'Feuille Spécifique Médecine',
            'code' => 'medecine_special',
            'type' => 'custom',
            'is_system' => false,
            'is_active' => true,
            'faculte_id' => $this->faculteB->id,
            'configuration' => [
                'orientation' => 'portrait',
                'paper' => 'A4',
                'blocks' => (new AttendanceTemplate(['type' => 'custom']))->blocsParDefaut(),
            ],
            'created_by' => $adminFaculteB->id,
        ]);

        // L'admin de la faculté A tente d'accéder au modèle de la faculté B -> 404
        $this->actingAs($adminFaculteA)
            ->get(route('admin.presences.modeles.edit', $templateFaculteB))
            ->assertNotFound();

        // L'admin de la faculté B a bien accès -> 200
        $this->actingAs($adminFaculteB)
            ->get(route('admin.presences.modeles.edit', $templateFaculteB))
            ->assertOk();
    }

    public function test_apercu_live_contient_donnees_fictives_sans_pii(): void
    {
        $template = AttendanceTemplate::where('code', 'classic')->firstOrFail();

        $composant = Livewire::actingAs($this->admin)
            ->test(AttendanceTemplateBuilder::class, ['initialTemplateId' => $template->id]);

        $html = $composant->get('previewHtml');

        $this->assertStringContainsString('BEN ALI', $html);
        $this->assertStringContainsString('Ahmed', $html);
        $this->assertStringContainsString('INS-2026-00001', $html);
        $this->assertStringContainsString('L1-DISI-A', $html);
        $this->assertStringContainsString('Dr. Khaled BEN SALEM', $html);
    }

    public function test_apercu_pdf_genere_un_binaire_valide(): void
    {
        $template = AttendanceTemplate::where('code', 'classic')->firstOrFail();

        $response = $this->actingAs($this->admin)
            ->get(route('admin.presences.modeles.apercu-pdf', $template));

        $response->assertOk();
        $this->assertEquals('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_les_8_modeles_systeme_sont_tous_rendus_avec_succes(): void
    {
        $codes = ['classic', 'emargement', 'detailed', 'td', 'tp', 'exam', 'double_signature', 'qr'];
        $renderer = app(AttendanceTemplateRenderer::class);

        foreach ($codes as $code) {
            $template = AttendanceTemplate::where('code', $code)->firstOrFail();
            $html = $renderer->rendreHtml($template, [
                'titre' => 'Test Rendu '.$code,
                'is_preview' => true,
            ], $this->admin);

            $this->assertNotEmpty($html, "Le rendu pour {$code} ne doit pas être vide.");
            $this->assertStringContainsString('BEN ALI', $html, "Le modèle {$code} doit contenir les données fictives.");
            $this->assertStringContainsString('Ahmed', $html, "Le modèle {$code} doit contenir les données fictives.");
        }
    }
}
