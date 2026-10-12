<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Attendance\Models\TeacherPresence;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP SCHEDULE-03 — Cycle de vie : transitions matricielles,
 * verrouillage, garde suppression avec présences.
 */
class ScheduleCycleDeVieTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private User $admin;

    private Schedule $planning;

    private ScheduleSlot $slot;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.schedule.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FA', 'code' => 'FA', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'S', 'email' => 'sco.'.uniqid().'@univ.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $groupe = Group::create([
            'code' => 'G-'.uniqid(), 'nom' => 'G', 'niveau' => 'L1',
            'faculte_id' => $this->faculte->id, 'actif' => true,
        ]);
        $matiere = Matiere::create([
            'code' => 'MC-'.uniqid(), 'nom' => 'Matiere Cycle', 'niveau' => 'L1',
            'semestre' => 1, 'coefficient' => 2, 'credits' => 4,
        ]);
        $enseignant = Teacher::create(['nom' => 'Nom', 'prenom' => 'Prenom']);

        $this->planning = Schedule::create([
            'nom' => 'EDT', 'annee_universitaire_id' => $annee->id,
            'groupe_id' => $groupe->id, 'semestre' => 1, 'statut' => 'brouillon',
        ]);
        $this->slot = ScheduleSlot::create([
            'schedule_id' => $this->planning->id, 'matiere_id' => $matiere->id,
            'teacher_id' => $enseignant->id, 'day_of_week' => 'monday',
            'start_time' => '08:00:00', 'end_time' => '10:00:00',
            'type' => 'course', 'status' => 'scheduled',
        ]);
    }

    public function test_transitions_valides_brouillon_valide_publie_verrouille(): void
    {
        foreach (['valide', 'publie', 'verrouille'] as $cible) {
            $this->actingAs($this->admin)
                ->post(route('admin.emplois-du-temps.transiter', [$this->planning, $cible]))
                ->assertRedirect()
                ->assertSessionHasNoErrors()
                ->assertSessionHas('success');
        }

        $this->assertSame('verrouille', $this->planning->fresh()->statut);
    }

    public function test_transition_interdite_refusee_sans_500(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.emplois-du-temps.transiter', [$this->planning, 'publie']))
            ->assertRedirect()
            ->assertSessionHasErrors('planning');

        $this->assertSame('brouillon', $this->planning->fresh()->statut);
    }

    public function test_double_soumission_transiter_sans_doublon(): void
    {
        $route = route('admin.emplois-du-temps.transiter', [$this->planning, 'valide']);

        $this->actingAs($this->admin)->post($route)->assertSessionHas('success');
        // Rejeu : déjà valide → transition identique interdite, refus propre.
        $this->actingAs($this->admin)->post($route)->assertSessionHasErrors('planning');

        $this->assertSame('valide', $this->planning->fresh()->statut);
    }

    public function test_ecriture_sur_planning_verrouille_refusee(): void
    {
        $this->planning->update(['statut' => 'verrouille']);

        // Création.
        $this->actingAs($this->admin)->post(
            route('admin.emplois-du-temps.slots.store', $this->planning),
            ['matiere_id' => $this->slot->matiere_id, 'day_of_week' => 'tuesday', 'start_time' => '10:00', 'end_time' => '12:00']
        )->assertSessionHasErrors();

        // Modification.
        $this->actingAs($this->admin)->patch(
            route('admin.emplois-du-temps.slots.update', $this->slot),
            ['start_time' => '09:00', 'end_time' => '11:00']
        )->assertSessionHasErrors();

        // Suppression.
        $this->actingAs($this->admin)->delete(
            route('admin.emplois-du-temps.slots.destroy', $this->slot)
        )->assertSessionHasErrors();

        $this->assertSame(1, ScheduleSlot::where('schedule_id', $this->planning->id)->count());
        $this->assertSame('08:00:00', $this->slot->fresh()->start_time);
    }

    public function test_suppression_creneau_avec_presences_refusee_sans_500(): void
    {
        TeacherPresence::create([
            'schedule_slot_id' => $this->slot->id,
            'teacher_id' => $this->slot->teacher_id,
            'date' => '2026-11-03',
        ]);

        $this->actingAs($this->admin)->delete(
            route('admin.emplois-du-temps.slots.destroy', $this->slot)
        )->assertRedirect()->assertSessionHasErrors('creneau');

        $this->assertDatabaseHas('schedule_slots', ['id' => $this->slot->id]);
        $this->assertDatabaseHas('teacher_presences', ['schedule_slot_id' => $this->slot->id]);
    }

    public function test_suppression_sans_attache_historisee(): void
    {
        $this->actingAs($this->admin)->delete(
            route('admin.emplois-du-temps.slots.destroy', $this->slot)
        )->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseMissing('schedule_slots', ['id' => $this->slot->id]);
        // Audit conservé malgré la suppression (slot dissocié, pas effacé).
        $this->assertDatabaseHas('schedule_changes', [
            'schedule_slot_id' => null,
            'change_type' => 'deleted',
        ]);
    }
}
