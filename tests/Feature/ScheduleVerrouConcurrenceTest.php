<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Services\ScheduleService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SCHEDULE-03/12 (S03-5, S05-7, S12-2) — courses vérification→écriture :
 * le verrouillage d'un planning survenant entre le chargement des modèles
 * et l'écriture doit être re-vérifié SOUS transaction, jamais sur une
 * instance potentiellement périmée (convention projet : re-vérification
 * dans la transaction, comme inscriptions/affectations).
 */
class ScheduleVerrouConcurrenceTest extends TestCase
{
    use RefreshDatabase;

    private Schedule $planning;

    private ScheduleSlot $slot;

    private Matiere $matiere;

    protected function setUp(): void
    {
        parent::setUp();

        $faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'x', 'active' => true]);
        $groupe = Group::create(['code' => 'L1-V', 'nom' => 'L1 V', 'faculte_id' => $faculte->id, 'niveau' => 'L1', 'actif' => true]);
        $annee = AnneeUniversitaire::create(['libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true]);
        $this->matiere = Matiere::create(['code' => 'CONC-1', 'nom' => 'Concurrence', 'niveau' => 'L1', 'semestre' => 1]);

        $this->planning = Schedule::create([
            'annee_universitaire_id' => $annee->id, 'semestre' => 1,
            'groupe_id' => $groupe->id, 'nom' => 'P', 'actif' => true,
        ]);

        $this->slot = ScheduleSlot::create([
            'schedule_id' => $this->planning->id, 'matiere_id' => $this->matiere->id,
            'day_of_week' => 'monday', 'start_time' => '08:00:00', 'end_time' => '10:00:00',
            'type' => 'course', 'status' => 'scheduled',
        ]);
    }

    /**
     * Le planning est verrouillé APRÈS le chargement du créneau (simule la
     * course chargement → verrouillage → écriture). L'annulation doit être
     * refusée sur l'état réel, pas sur l'instance périmée.
     */
    public function test_annulation_refusee_si_planning_verrouille_apres_chargement(): void
    {
        $slot = ScheduleSlot::findOrFail($this->slot->id); // chargé avant le verrou

        Schedule::whereKey($this->planning->id)->update(['statut' => 'verrouille']);

        try {
            app(ScheduleService::class)->annulerCreneau($slot, null, 'test course');
            $this->fail('L’annulation aurait dû être refusée : planning verrouillé.');
        } catch (DomainException) {
            // attendu
        }

        $this->assertSame('scheduled', $this->slot->fresh()->status, 'Aucune écriture ne doit passer sur un planning verrouillé.');
        $this->assertDatabaseMissing('schedule_changes', ['schedule_slot_id' => $slot->id, 'change_type' => 'cancelled']);
    }

    public function test_modification_refusee_si_planning_verrouille_apres_chargement(): void
    {
        $slot = ScheduleSlot::findOrFail($this->slot->id);

        Schedule::whereKey($this->planning->id)->update(['statut' => 'verrouille']);

        try {
            app(ScheduleService::class)->modifierCreneau($slot, ['notes' => 'Course simulée']);
            $this->fail('La modification aurait dû être refusée : planning verrouillé.');
        } catch (DomainException) {
            // attendu
        }

        $this->assertNull($this->slot->fresh()->notes);
    }

    public function test_creation_refusee_si_planning_verrouille_apres_chargement(): void
    {
        $planning = Schedule::findOrFail($this->planning->id);

        Schedule::whereKey($this->planning->id)->update(['statut' => 'verrouille']);

        try {
            app(ScheduleService::class)->creerCreneau($planning, [
                'matiere_id' => $this->matiere->id,
                'day_of_week' => 'tuesday', 'start_time' => '08:00:00', 'end_time' => '10:00:00',
                'type' => 'course',
            ]);
            $this->fail('La création aurait dû être refusée : planning verrouillé.');
        } catch (DomainException) {
            // attendu
        }

        $this->assertDatabaseMissing('schedule_slots', [
            'schedule_id' => $this->planning->id, 'day_of_week' => 'tuesday',
        ]);
    }

    public function test_suppression_refusee_si_planning_verrouille_apres_chargement(): void
    {
        $slot = ScheduleSlot::findOrFail($this->slot->id);

        Schedule::whereKey($this->planning->id)->update(['statut' => 'verrouille']);

        try {
            app(ScheduleService::class)->supprimerCreneau($slot);
            $this->fail('La suppression aurait dû être refusée : planning verrouillé.');
        } catch (DomainException) {
            // attendu
        }

        $this->assertDatabaseHas('schedule_slots', ['id' => $slot->id]);
    }

    public function test_exception_refusee_si_planning_verrouille_apres_chargement(): void
    {
        $slot = ScheduleSlot::findOrFail($this->slot->id);

        Schedule::whereKey($this->planning->id)->update(['statut' => 'verrouille']);

        try {
            app(ScheduleService::class)->creerException($slot, [
                'exception_date' => now()->addDays(3)->toDateString(),
                'status' => 'cancelled', 'reason' => 'Course simulée',
            ]);
            $this->fail('L’exception aurait dû être refusée : planning verrouillé.');
        } catch (DomainException) {
            // attendu
        }

        $this->assertDatabaseMissing('schedule_exceptions', ['schedule_slot_id' => $slot->id]);
    }

    /**
     * Déjà conforme : `transiterPlanning` re-relit l'état dans la transaction.
     * Le test verrouille le contrat (transition depuis un état périmé refusée).
     */
    public function test_transition_reverifie_etat_reel_dans_la_transaction(): void
    {
        $planning = Schedule::findOrFail($this->planning->id);
        $planning->update(['statut' => 'valide']);
        $planning->refresh();

        // État changé extérieurement après chargement : l'instance est périmée.
        Schedule::whereKey($planning->id)->update(['statut' => 'verrouille']);

        $this->expectException(DomainException::class);
        app(ScheduleService::class)->transiterPlanning($planning, 'publie');
    }

    /**
     * La restauration d'un créneau annulé reste possible hors verrou
     * (parcours légitime préservé — pas de régression du garde-fou).
     */
    public function test_restauration_possible_sur_planning_non_verrouille(): void
    {
        $slot = ScheduleSlot::findOrFail($this->slot->id);
        app(ScheduleService::class)->annulerCreneau($slot, null, 'annulation test');
        $this->assertSame('cancelled', $slot->fresh()->status);

        app(ScheduleService::class)->restaurerCreneau($slot->fresh(), null, 'restauration test');
        $this->assertSame('scheduled', $slot->fresh()->status);
    }
}
