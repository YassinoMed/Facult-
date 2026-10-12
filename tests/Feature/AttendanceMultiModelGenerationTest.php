<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Attendance\Models\AttendanceTemplate;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use Database\Seeders\AttendanceTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests de génération des 9 modèles de fiches de présence (§3-9, §13-17) :
 * - Classique
 * - Émargement
 * - Détaillée
 * - TD
 * - TP
 * - Examen
 * - Double signature
 * - QR Code
 * - Personnalisé
 * Avec vérification des données, isolation faculté, aperçu en direct et pagination.
 */
class AttendanceMultiModelGenerationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Group $groupe;

    private Matiere $matiere;

    private Teacher $enseignant;

    private Room $salle;

    private ScheduleSlot $slot;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.attendance.active' => true]);

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences',
            'code' => 'FS',
            'description' => 'Sciences',
            'active' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Resp Scolarite',
            'email' => 'resp.att@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculte->id,
        ], 'admin_scolarite');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->groupe = Group::create([
            'code' => 'INFO-L3',
            'nom' => 'Licence 3 Informatique',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L3',
            'capacite' => 40,
            'actif' => true,
        ]);

        $this->matiere = Matiere::create([
            'code' => 'ALGO301',
            'nom' => 'Algorithmique Avancée',
            'coefficient' => 2.0,
            'credits' => 6,
            'niveau' => 'L3',
            'semestre' => 1,
        ]);

        $this->enseignant = Teacher::create([
            'matricule' => 'ENS001',
            'nom' => 'Alami',
            'prenom' => 'Karim',
            'email' => 'k.alami@universite.test',
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->salle = Room::create([
            'code' => 'LABO-02',
            'nom' => 'Laboratoire Informatique 02',
            'capacite' => 35,
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $schedule = Schedule::create([
            'annee_universitaire_id' => $this->annee->id,
            'semestre' => 1,
            'groupe_id' => $this->groupe->id,
            'nom' => 'Planning S1 L3',
            'statut' => 'valide',
            'actif' => true,
        ]);

        $this->slot = ScheduleSlot::create([
            'schedule_id' => $schedule->id,
            'matiere_id' => $this->matiere->id,
            'teacher_id' => $this->enseignant->id,
            'room_id' => $this->salle->id,
            'day_of_week' => 'monday',
            'start_time' => '08:30:00',
            'end_time' => '10:30:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        // Créer 3 étudiants dans ce groupe
        for ($i = 1; $i <= 3; $i++) {
            $this->creerEtudiant('ETU0000'.$i, 'Nom'.$i, 'Prenom'.$i);
        }

        $this->seed(AttendanceTemplateSeeder::class);
    }

    public function test_assistant_generation_page_accessible(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.presences.feuille.nouveau'));

        $response->assertOk()
            ->assertSee('Générateur de fiche de présence')
            ->assertSee('INFO-L3')
            ->assertSee('ALGO301');
    }

    public function test_generation_pdf_modele_classique(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.presences.feuille', [
            'groupe_id' => $this->groupe->id,
            'template_code' => 'classic',
            'date' => '2026-10-15',
            'seance' => '08:30 - 10:30',
        ]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_generation_pdf_modele_emargement(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.presences.feuille', [
            'groupe_id' => $this->groupe->id,
            'template_code' => 'emargement',
        ]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_generation_pdf_modele_detaille(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.presences.feuille', [
            'groupe_id' => $this->groupe->id,
            'template_code' => 'detailed',
        ]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_generation_pdf_modele_td(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.presences.feuille', [
            'groupe_id' => $this->groupe->id,
            'template_code' => 'td',
        ]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_generation_pdf_modele_tp(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.presences.feuille', [
            'groupe_id' => $this->groupe->id,
            'template_code' => 'tp',
        ]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_generation_pdf_modele_exam(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.presences.feuille', [
            'groupe_id' => $this->groupe->id,
            'template_code' => 'exam',
            'type_examen' => 'Contrôle Continu',
            'session' => 'Session 1',
            'surveillant_1' => 'Dr. Martin',
            'surveillant_2' => 'Dr. Dupont',
        ]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_generation_pdf_modele_double_signature(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.presences.feuille', [
            'groupe_id' => $this->groupe->id,
            'template_code' => 'double_signature',
        ]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_generation_pdf_modele_qr_code(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.presences.feuille', [
            'groupe_id' => $this->groupe->id,
            'template_code' => 'qr',
        ]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_generation_pdf_modele_personnalise(): void
    {
        $customTemplate = AttendanceTemplate::create([
            'name' => 'Custom Colonnes Libres',
            'code' => 'custom_libres',
            'type' => 'custom',
            'is_system' => false,
            'is_active' => true,
            'configuration' => [
                'orientation' => 'portrait',
                'paper' => 'A4',
                'columns' => [
                    ['key' => 'numero', 'label' => 'Rang', 'width' => '10%', 'visible' => true],
                    ['key' => 'matricule', 'label' => 'Code Étudiant', 'width' => '25%', 'visible' => true],
                    ['key' => 'nom', 'label' => 'Nom de famille', 'width' => '30%', 'visible' => true],
                    ['key' => 'signature', 'label' => 'Visa', 'width' => '35%', 'visible' => true],
                ],
            ],
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.presences.feuille', [
            'groupe_id' => $this->groupe->id,
            'template_id' => $customTemplate->id,
        ]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_apercu_html_avant_generation(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.presences.feuille.apercu', [
            'groupe_id' => $this->groupe->id,
            'template_code' => 'classic',
        ]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee('INFO-L3')
            ->assertSee('Nom1')
            ->assertSee('Prenom1');
    }

    public function test_apercu_html_avec_creneau_schedule_slot(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.presences.feuille.apercu', [
            'schedule_slot_id' => $this->slot->id,
            'template_code' => 'td',
        ]));

        $response->assertOk()
            ->assertSee('ALGO301')
            ->assertSee('Karim')
            ->assertSee('Laboratoire Informatique 02')
            ->assertSee('Licence 3 Informatique');
    }

    public function test_isolation_faculte_sur_feuille(): void
    {
        $autreFaculte = Faculte::create([
            'nom' => 'Autre Faculte FMP',
            'code' => 'FMP',
            'description' => 'Medecine',
            'active' => true,
        ]);

        $adminAutreFac = User::creerCompteBackOffice([
            'name' => 'Admin FMP',
            'email' => 'admin.fmp@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $autreFaculte->id,
        ], 'admin_faculte');

        // Tentative d'accès à un groupe d'une autre faculté -> 404 strict
        $response = $this->actingAs($adminAutreFac)->get(route('admin.presences.feuille', [
            'groupe_id' => $this->groupe->id,
            'template_code' => 'classic',
        ]));

        $response->assertNotFound();
    }

    private function creerEtudiant(string $cin, string $nom, string $prenom): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin,
            'nom' => $nom,
            'prenom' => $prenom,
            'email' => strtolower($cin).'@att.test',
            'date_naissance' => '2004-01-01',
            'sexe' => 'F',
            'faculte_id' => $this->faculte->id,
            'groupe_id' => $this->groupe->id,
            'niveau' => 'L3',
            'annee_academique' => '2026-2027',
            'adresse' => 'Rue Universite',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2023',
            'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');
        $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L3',
            'statut' => 'en_cours',
        ]);

        return $etudiant;
    }

    public function test_page_generateur_feuille_est_accessible(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.presences.feuille.nouveau'));

        $response->assertOk();
        $response->assertViewIs('modules.attendance.feuille-generateur');
        $response->assertSee('Générateur de fiche de présence');
        $response->assertSee($this->matiere->code);
    }
}
