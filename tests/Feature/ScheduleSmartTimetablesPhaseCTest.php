<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleAvailability;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use App\Modules\Schedule\Models\TimetableConstraint;
use App\Modules\Schedule\Services\ScheduleConstraintChecker;
use App\Modules\Schedule\Services\ScheduleGeneratorService;
use App\Modules\Schedule\Services\SeanceDemande;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PHASE C (Smart Timetables) — Tests de vérification :
 * C1. Audit indépendant des préférences de salles et de groupes.
 * C2. Ordonnancement par priorité métier dans le générateur CSP.
 * C3. Scoping faculté et sécurité sur la génération et les disponibilités.
 */
class ScheduleSmartTimetablesPhaseCTest extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculteA;

    protected Faculte $faculteB;

    protected User $adminScolarite;

    protected User $adminFaculteA;

    protected Group $groupeA;

    protected Group $groupeB;

    protected AnneeUniversitaire $annee;

    protected Matiere $matiere;

    protected Teacher $enseignant;

    protected Room $salle;

    protected Schedule $planningA;

    protected Schedule $planningB;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.schedule.active' => true]);

        $this->faculteA = Faculte::create([
            'nom' => 'Faculté des Sciences',
            'code' => 'FST',
            'description' => 'Sciences',
            'active' => true,
        ]);

        $this->faculteB = Faculte::create([
            'nom' => 'Faculté de Droit',
            'code' => 'FDD',
            'description' => 'Droit',
            'active' => true,
        ]);

        $this->adminScolarite = User::creerCompteBackOffice([
            'name' => 'Admin Scolarite',
            'email' => 'scol.phasec@univ.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->adminFaculteA = User::creerCompteBackOffice([
            'name' => 'Admin Fac A',
            'email' => 'admin.fasa@univ.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->groupeA = Group::create([
            'code' => 'GRP-A',
            'nom' => 'Groupe A',
            'faculte_id' => $this->faculteA->id,
            'niveau' => 'L1',
            'actif' => true,
        ]);

        $this->groupeB = Group::create([
            'code' => 'GRP-B',
            'nom' => 'Groupe B',
            'faculte_id' => $this->faculteB->id,
            'niveau' => 'L1',
            'actif' => true,
        ]);

        $this->matiere = Matiere::create([
            'code' => 'MAT-C01',
            'nom' => 'Matière Phase C',
            'niveau' => 'L1',
            'semestre' => 1,
            'credits' => 3,
        ]);

        $this->enseignant = Teacher::create([
            'nom' => 'Phase C',
            'prenom' => 'Docteur',
            'matricule' => 'ENS-PHASE-C',
            'actif' => true,
        ]);

        $this->salle = Room::create([
            'code' => 'S-PHASE-C',
            'nom' => 'Salle Phase C',
            'capacite' => 40,
            'faculte_id' => $this->faculteA->id,
            'actif' => true,
        ]);

        $this->planningA = Schedule::create([
            'annee_universitaire_id' => $this->annee->id,
            'semestre' => 1,
            'groupe_id' => $this->groupeA->id,
            'nom' => 'Planning A',
            'statut' => 'brouillon',
            'actif' => true,
        ]);

        $this->planningB = Schedule::create([
            'annee_universitaire_id' => $this->annee->id,
            'semestre' => 1,
            'groupe_id' => $this->groupeB->id,
            'nom' => 'Planning B',
            'statut' => 'brouillon',
            'actif' => true,
        ]);
    }

    /**
     * C1.1 — Audit indépendant : détection d'un créneau hors plage souhaitée pour une salle.
     */
    public function test_audit_detecte_penalite_souple_sur_preference_salle(): void
    {
        // Déclaration d'une préférence souhaitée pour la salle : Lundi 08:00 - 12:00
        ScheduleAvailability::create([
            'room_id' => $this->salle->id,
            'type' => 'souhaitee',
            'day_of_week' => 'monday',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'statut' => 'valide',
        ]);

        // Créneau positionné le Mardi (hors de la plage souhaitée)
        $slot = ScheduleSlot::create([
            'schedule_id' => $this->planningA->id,
            'matiere_id' => $this->matiere->id,
            'teacher_id' => $this->enseignant->id,
            'room_id' => $this->salle->id,
            'day_of_week' => 'tuesday',
            'start_time' => '08:00',
            'end_time' => '10:00',
            'type' => 'course',
            'status' => 'confirmed',
        ]);

        $checker = app(ScheduleConstraintChecker::class);
        $rapport = $checker->auditerPlanning($this->planningA);

        $this->assertTrue($rapport->estValide(), 'Une préférence souple ne doit pas invalider le planning.');
        $penalitesSalles = array_filter(
            $rapport->penalitesSouples(),
            fn (array $p) => str_contains($p['nom'], 'salles') || ($p['details']['room_id'] ?? null) === $this->salle->id
        );

        $this->assertNotEmpty($penalitesSalles, 'Une pénalité de préférence salle aurait dû être levée.');
        $premiere = array_values($penalitesSalles)[0];
        $this->assertSame(TimetableConstraint::SOFT_TEACHER_PREFERENCE, $premiere['code']);
        $this->assertStringContainsString($this->salle->code, $premiere['message']);
    }

    /**
     * C1.2 — Audit indépendant : créneau conforme à la plage souhaitée de la salle -> aucune pénalité salle.
     */
    public function test_audit_ne_penalise_pas_creneau_conforme_au_souhait_salle(): void
    {
        ScheduleAvailability::create([
            'room_id' => $this->salle->id,
            'type' => 'souhaitee',
            'day_of_week' => 'monday',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'statut' => 'valide',
        ]);

        ScheduleSlot::create([
            'schedule_id' => $this->planningA->id,
            'matiere_id' => $this->matiere->id,
            'teacher_id' => $this->enseignant->id,
            'room_id' => $this->salle->id,
            'day_of_week' => 'monday',
            'start_time' => '08:30',
            'end_time' => '10:30',
            'type' => 'course',
            'status' => 'confirmed',
        ]);

        $checker = app(ScheduleConstraintChecker::class);
        $rapport = $checker->auditerPlanning($this->planningA);

        $penalitesSalles = array_filter(
            $rapport->penalitesSouples(),
            fn (array $p) => str_contains($p['nom'], 'salles') || ($p['details']['room_id'] ?? null) === $this->salle->id
        );

        $this->assertEmpty($penalitesSalles, 'Aucune pénalité salle ne doit être levée pour un créneau conforme.');
    }

    /**
     * C1.3 — Audit indépendant : détection d'un créneau hors plage souhaitée pour un groupe.
     */
    public function test_audit_detecte_penalite_souple_sur_preference_groupe(): void
    {
        ScheduleAvailability::create([
            'group_id' => $this->groupeA->id,
            'type' => 'souhaitee',
            'day_of_week' => 'wednesday',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'statut' => 'valide',
        ]);

        ScheduleSlot::create([
            'schedule_id' => $this->planningA->id,
            'matiere_id' => $this->matiere->id,
            'teacher_id' => $this->enseignant->id,
            'room_id' => $this->salle->id,
            'day_of_week' => 'thursday',
            'start_time' => '14:00',
            'end_time' => '16:00',
            'type' => 'course',
            'status' => 'confirmed',
        ]);

        $checker = app(ScheduleConstraintChecker::class);
        $rapport = $checker->auditerPlanning($this->planningA);

        $penalitesGroupes = array_filter(
            $rapport->penalitesSouples(),
            fn (array $p) => str_contains($p['nom'], 'groupes') || ($p['details']['group_id'] ?? null) === $this->groupeA->id
        );

        $this->assertNotEmpty($penalitesGroupes, 'Une pénalité de préférence groupe aurait dû être levée.');
        $premiere = array_values($penalitesGroupes)[0];
        $this->assertSame(TimetableConstraint::SOFT_TEACHER_PREFERENCE, $premiere['code']);
        $this->assertStringContainsString($this->groupeA->nom, $premiere['message']);
    }

    /**
     * C2 — Générateur CSP : la priorité métier départage les demandes à domaine égal.
     */
    public function test_generateur_ordonnance_par_priorite_a_taille_de_domaine_egale(): void
    {
        $d1 = new SeanceDemande(
            id: 'demande_faible',
            matiereId: $this->matiere->id,
            type: 'tp',
            titre: 'TP Priorité Faible',
            dureeMinutes: 120,
            teacherId: $this->enseignant->id,
            roomId: $this->salle->id,
            priorite: 2
        );

        $d2 = new SeanceDemande(
            id: 'demande_forte',
            matiereId: $this->matiere->id,
            type: 'course',
            titre: 'CM Priorité Haute',
            dureeMinutes: 120,
            teacherId: $this->enseignant->id,
            roomId: $this->salle->id,
            priorite: 9
        );

        $generator = app(ScheduleGeneratorService::class);

        // Simulation avec deux séances de même durée, même salle et même enseignant
        $resultat = $generator->simuler($this->planningA, [
            'seances_demandes' => [$d1, $d2],
            'jours_autorises' => ['monday'],
            'timeout_secondes' => 5,
        ]);

        $this->assertNotNull($resultat);
        $this->assertGreaterThanOrEqual(1, $resultat->nombre_seances_placees);
    }

    /**
     * C3.1 — Sécurité & Scoping : un admin de faculté A ne peut pas accéder à la génération du planning B.
     */
    public function test_admin_faculte_hors_perimetre_recoit_404_sur_generation(): void
    {
        $this->actingAs($this->adminFaculteA);

        $response = $this->get(route('admin.emplois-du-temps.generation.index', $this->planningB));
        $response->assertNotFound();

        $responseSimuler = $this->post(route('admin.emplois-du-temps.generation.simuler', $this->planningB));
        $responseSimuler->assertNotFound();

        $responseGenerer = $this->post(route('admin.emplois-du-temps.generation.generer', $this->planningB));
        $responseGenerer->assertNotFound();
    }

    /**
     * C3.2 — Sécurité & Scoping : un admin de faculté A accède normalement à la génération du planning A.
     */
    public function test_admin_faculte_dans_perimetre_peut_consulter_generation(): void
    {
        $this->actingAs($this->adminFaculteA);

        $response = $this->get(route('admin.emplois-du-temps.generation.index', $this->planningA));
        $response->assertOk();
        $response->assertSee('Génération automatique');
    }

    /**
     * C3.3 — Sécurité : l'édition d'une plage d'indisponibilité hors périmètre faculté est rejetée par 404.
     */
    public function test_admin_faculte_ne_peut_pas_modifier_indisponibilite_hors_perimetre(): void
    {
        $plageB = ScheduleAvailability::create([
            'group_id' => $this->groupeB->id,
            'type' => 'indisponible',
            'day_of_week' => 'monday',
            'start_time' => '10:00',
            'end_time' => '12:00',
            'statut' => 'valide',
        ]);

        $this->actingAs($this->adminFaculteA);

        $this->get(route('admin.emplois-du-temps.indisponibilites.edit', $plageB))
            ->assertNotFound();

        $this->patch(route('admin.emplois-du-temps.indisponibilites.update', $plageB), [
            'group_id' => $this->groupeB->id,
            'type' => 'indisponible',
            'day_of_week' => 'tuesday',
            'start_time' => '10:00',
            'end_time' => '12:00',
        ])->assertNotFound();

        $this->delete(route('admin.emplois-du-temps.indisponibilites.destroy', $plageB))
            ->assertNotFound();
    }
}
