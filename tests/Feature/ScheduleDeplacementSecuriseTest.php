<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use App\Modules\Schedule\Services\ScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SCHEDULE-06 — Déplacement sécurisé : la destination est re-vérifiée
 * DANS la transaction (`modifierCreneau` → `sousVerrous`), jamais sur la
 * seule simulation du dry-run ; échec = rollback sans état partiel ;
 * idempotence séquentielle ; inexistants 404 ; comportements documentés.
 */
class ScheduleDeplacementSecuriseTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Schedule $planning;

    private ScheduleSlot $slot;

    private ScheduleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.schedule.active' => true]);

        $faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'x', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Sco', 'email' => 'sco.depl@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $groupe = Group::create([
            'code' => 'G-DEP', 'nom' => 'Groupe Dep', 'faculte_id' => $faculte->id,
            'niveau' => 'L1', 'actif' => true,
        ]);
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->planning = Schedule::create([
            'annee_universitaire_id' => $annee->id, 'semestre' => 1,
            'groupe_id' => $groupe->id, 'nom' => 'P', 'actif' => true,
        ]);

        $matiere = Matiere::create(['code' => 'DEP101', 'nom' => 'Deplacement', 'niveau' => 'L1', 'semestre' => 1]);
        $this->service = app(ScheduleService::class);
        ['slot' => $this->slot] = $this->service->creerCreneau($this->planning, [
            'matiere_id' => $matiere->id,
            'teacher_id' => Teacher::create(['nom' => 'Prof', 'prenom' => 'Dep'])->id,
            'room_id' => Room::create(['code' => 'D1', 'capacite' => 30])->id,
            'day_of_week' => 'monday', 'start_time' => '08:00', 'end_time' => '10:00', 'type' => 'course',
        ], $this->admin->id);
    }

    /** Cible utilisée par les scénarios (sans conflit par défaut). */
    private function cible(): array
    {
        return ['day_of_week' => 'tuesday', 'start_time' => '10:00', 'end_time' => '12:00', 'room_id' => null];
    }

    public function test_deplacement_refuse_sur_planning_verrouille_sans_ecriture_ni_audit(): void
    {
        Schedule::whereKey($this->planning->id)->update(['statut' => 'verrouille']);

        $this->actingAs($this->admin)
            ->patchJson(route('admin.emplois-du-temps.slots.deplacer', $this->slot), $this->cible())
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Planning verrouillé : toute modification exige un retour au brouillon.');

        $this->assertSame('monday', $this->slot->fresh()->day_of_week, 'Aucune écriture sur planning verrouillé.');
        $this->assertDatabaseMissing('schedule_changes', [
            'schedule_slot_id' => $this->slot->id, 'change_type' => 'time_changed',
        ]);
    }

    public function test_double_soumission_sequentielle_idempotente(): void
    {
        $route = route('admin.emplois-du-temps.slots.deplacer', $this->slot);

        $this->actingAs($this->admin)->patchJson($route, $this->cible())->assertOk();
        $this->actingAs($this->admin)->patchJson($route, $this->cible())->assertOk();

        $frais = $this->slot->fresh();
        $this->assertSame('tuesday', $frais->day_of_week);
        $this->assertSame('10:00', substr((string) $frais->start_time, 0, 5));
        $this->assertSame('12:00', substr((string) $frais->end_time, 0, 5));
        $this->assertSame(1, Schedule::whereKey($this->planning->id)->count(), 'Aucune duplication de planning.');
    }

    public function test_deplacement_id_inexistant_404(): void
    {
        $this->actingAs($this->admin)
            ->patchJson(str_replace((string) $this->slot->id, '999999',
                route('admin.emplois-du-temps.slots.deplacer', $this->slot)), $this->cible())
            ->assertNotFound();
    }

    public function test_deplacement_creneau_annule_conserve_statut_annule(): void
    {
        // Comportement actuel documenté : déplacer un créneau annulé change
        // sa position mais JAMAIS son statut (l'annulation n'est pas un
        // déplacement — la restauration est une action dédiée).
        $this->service->annulerCreneau($this->slot, $this->admin->id, 'annulation préalable');
        $this->assertSame('cancelled', $this->slot->fresh()->status);

        $this->actingAs($this->admin)
            ->patchJson(route('admin.emplois-du-temps.slots.deplacer', $this->slot), $this->cible())
            ->assertOk();

        $frais = $this->slot->fresh();
        $this->assertSame('tuesday', $frais->day_of_week);
        $this->assertSame('cancelled', $frais->status, 'Un créneau annulé déplacé reste annulé.');
    }

    public function test_deplacement_annulation_restauration_separee(): void
    {
        // « Annuler un déplacement » = historique (pas d'undo) ; la
        // restauration concerne un créneau ANNULÉ (S06-4 clarifié).
        $this->service->annulerCreneau($this->slot, $this->admin->id, 'annulation');
        $frais = $this->service->restaurerCreneau($this->slot->fresh(), $this->admin->id);

        $this->assertSame('scheduled', $frais->fresh()->status);
        $this->assertDatabaseHas('schedule_changes', [
            'schedule_slot_id' => $this->slot->id, 'change_type' => 'cancelled',
        ]);
        $this->assertDatabaseHas('schedule_changes', [
            'schedule_slot_id' => $this->slot->id, 'change_type' => 'restored',
        ]);
    }

    public function test_echec_conflit_apres_simulation_ancienne_sans_ecriture(): void
    {
        // La simulation (dry-run) peut devenir périmée : le déplacement
        // réel re-vérifie au moment de la transaction côté service.
        $matiere2 = Matiere::create(['code' => 'DEP102', 'nom' => 'Blocage', 'niveau' => 'L1', 'semestre' => 1]);
        $this->service->creerCreneau($this->planning, [
            'matiere_id' => $matiere2->id, 'teacher_id' => $this->slot->teacher_id,
            'room_id' => null, 'day_of_week' => 'tuesday',
            'start_time' => '10:00', 'end_time' => '12:00', 'type' => 'td',
        ], $this->admin->id);

        $this->actingAs($this->admin)
            ->patchJson(route('admin.emplois-du-temps.slots.deplacer', $this->slot), $this->cible())
            ->assertUnprocessable();

        $this->assertSame('monday', $this->slot->fresh()->day_of_week, 'Rollback complet : position d\'origine intacte.');
    }
}
