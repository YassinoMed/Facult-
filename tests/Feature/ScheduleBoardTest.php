<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\Teacher;
use App\Modules\Schedule\Services\ScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tableau déplaçable (§3-4) : vérification live sans écriture,
 * déplacement transactionnel avec conflits, périmètre, audit.
 */
class ScheduleBoardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.schedule.active' => true]);

        $faculte = Faculte::create([
            'nom' => 'Faculté des Sciences', 'code' => 'FST',
            'description' => 'S', 'active' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.board@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $this->groupe = Group::create([
            'code' => 'GI2-A', 'nom' => 'GI2 A', 'faculte_id' => $faculte->id,
            'niveau' => 'L1', 'filiere' => 'Informatique', 'actif' => true,
        ]);

        $this->planning = Schedule::create([
            'annee_universitaire_id' => $annee->id, 'semestre' => 1,
            'groupe_id' => $this->groupe->id, 'nom' => 'L1 — S1', 'actif' => true,
        ]);

        $this->matiere = Matiere::create([
            'code' => 'WEB101', 'nom' => 'Web', 'coefficient' => 2,
            'credits' => 6, 'niveau' => 'L1', 'semestre' => 1,
        ]);
        $this->enseignant = Teacher::create(['nom' => 'Benali', 'prenom' => 'Sara']);
        $this->salle = Room::create(['code' => 'B12', 'capacite' => 40]);

        $service = app(ScheduleService::class);
        ['slot' => $this->slot] = $service->creerCreneau($this->planning, [
            'matiere_id' => $this->matiere->id, 'teacher_id' => $this->enseignant->id,
            'room_id' => $this->salle->id, 'day_of_week' => 'monday',
            'start_time' => '08:00', 'end_time' => '10:00', 'type' => 'course',
        ], $this->admin->id);
    }

    public function test_tableau_affiche_colonnes_et_cartes(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.emplois-du-temps.tableau', $this->planning))
            ->assertOk()
            ->assertSee('Lundi', false)
            ->assertSee('Déplacer', false)
            ->assertSee('tableauDeplacement', false);
    }

    public function test_verifier_disponible_sans_ecriture(): void
    {
        $reponse = $this->actingAs($this->admin)->postJson(
            route('admin.emplois-du-temps.slots.verifier', $this->slot),
            ['day_of_week' => 'tuesday', 'start_time' => '10:00', 'end_time' => '12:00', 'room_id' => null]
        )->assertOk();

        $this->assertTrue($reponse->json('disponible'));
        // Rien n'a bougé : dry-run.
        $this->assertSame('monday', $this->slot->fresh()->day_of_week);
    }

    public function test_verifier_signale_conflit(): void
    {
        // Second créneau même enseignant mardi 10h-12h.
        app(ScheduleService::class)->creerCreneau($this->planning, [
            'matiere_id' => $this->matiere->id, 'teacher_id' => $this->enseignant->id,
            'room_id' => null, 'day_of_week' => 'tuesday',
            'start_time' => '10:00', 'end_time' => '12:00', 'type' => 'td',
        ], $this->admin->id);

        $reponse = $this->actingAs($this->admin)->postJson(
            route('admin.emplois-du-temps.slots.verifier', $this->slot),
            ['day_of_week' => 'tuesday', 'start_time' => '10:00', 'end_time' => '12:00', 'room_id' => null]
        )->assertOk();

        $this->assertFalse($reponse->json('disponible'));
        $this->assertNotEmpty($reponse->json('conflits'));
    }

    public function test_deplacer_ok_transaction_et_audit(): void
    {
        $reponse = $this->actingAs($this->admin)->patchJson(
            route('admin.emplois-du-temps.slots.deplacer', $this->slot),
            ['day_of_week' => 'tuesday', 'start_time' => '10:00', 'end_time' => '12:00', 'room_id' => null]
        )->assertOk();

        $this->assertSame('tuesday', $reponse->json('slot.day_of_week'));
        $this->assertSame('tuesday', $this->slot->fresh()->day_of_week);
        $this->assertDatabaseHas('schedule_changes', ['schedule_slot_id' => $this->slot->id]);
    }

    public function test_deplacer_bloque_si_conflit_422(): void
    {
        app(ScheduleService::class)->creerCreneau($this->planning, [
            'matiere_id' => $this->matiere->id, 'teacher_id' => $this->enseignant->id,
            'room_id' => null, 'day_of_week' => 'tuesday',
            'start_time' => '10:00', 'end_time' => '12:00', 'type' => 'td',
        ], $this->admin->id);

        $this->actingAs($this->admin)->patchJson(
            route('admin.emplois-du-temps.slots.deplacer', $this->slot),
            ['day_of_week' => 'tuesday', 'start_time' => '10:00', 'end_time' => '12:00', 'room_id' => null]
        )->assertUnprocessable();

        // Rollback : position d'origine intacte.
        $this->assertSame('monday', $this->slot->fresh()->day_of_week);
    }

    public function test_deplacer_hors_perimetre_404(): void
    {
        $faculteB = Faculte::create([
            'nom' => 'FB', 'code' => 'FB', 'description' => 'B', 'active' => true,
        ]);
        $adminB = User::creerCompteBackOffice([
            'name' => 'Admin B', 'email' => 'b.board@universite.test',
            'password' => 'Motdepasse@123', 'faculte_id' => $faculteB->id,
        ], 'admin_faculte');

        $this->actingAs($adminB)->patchJson(
            route('admin.emplois-du-temps.slots.deplacer', $this->slot),
            ['day_of_week' => 'tuesday', 'start_time' => '10:00', 'end_time' => '12:00']
        )->assertNotFound();

        $this->actingAs($adminB)
            ->get(route('admin.emplois-du-temps.tableau', $this->planning))
            ->assertNotFound();
    }

    public function test_deplacer_sans_permission_403(): void
    {
        $agent = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => 'agent.board@universite.test',
            'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        $this->actingAs($agent)->patchJson(
            route('admin.emplois-du-temps.slots.deplacer', $this->slot),
            ['day_of_week' => 'tuesday', 'start_time' => '10:00', 'end_time' => '12:00']
        )->assertForbidden();
    }
}
