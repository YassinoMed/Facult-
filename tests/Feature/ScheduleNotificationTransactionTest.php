<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Schedule\Events\ScheduleChanged;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Services\ScheduleService;
use App\Services\NotificationService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * SCHEDULE-10 — Notifications et audit : jamais avant le commit,
 * jamais trompeuses (refus = zéro événement), jamais bloquantes
 * (échec listener ≠ mutation perdue). À la date de création de ce
 * fichier, annuler/restaurer/supprimer/exception/transiter/publier/
 * verrouiller/dupliquer dispatchaient DANS la transaction (listeners
 * synchrones : un échec de notification annulait la mutation).
 */
class ScheduleNotificationTransactionTest extends TestCase
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
            'name' => 'Sco', 'email' => 'sco.notif@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $groupe = Group::create([
            'code' => 'G-NTF', 'nom' => 'Groupe Notif', 'faculte_id' => $faculte->id,
            'niveau' => 'L1', 'actif' => true,
        ]);
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        // Fixture directe (pas de bruit d'événements de création).
        $this->planning = Schedule::create([
            'annee_universitaire_id' => $annee->id, 'semestre' => 1,
            'groupe_id' => $groupe->id, 'nom' => 'P', 'actif' => true,
        ]);
        $this->slot = ScheduleSlot::create([
            'schedule_id' => $this->planning->id,
            'matiere_id' => Matiere::create(['code' => 'NTF101', 'nom' => 'Notif', 'niveau' => 'L1', 'semestre' => 1])->id,
            'day_of_week' => 'monday', 'start_time' => '08:00:00', 'end_time' => '10:00:00',
            'type' => 'course', 'status' => 'scheduled',
        ]);

        $this->service = app(ScheduleService::class);
    }

    /**
     * NotifierPlanning cassé côté canal (NotificationService jette) +
     * groupe peuplé d'un étudiant pour que le listener atteigne le canal.
     */
    private function notifierCase(): void
    {
        $cassee = $this->mock(NotificationService::class);
        $cassee->shouldReceive('notifierEtudiant')->andThrow(new \RuntimeException('canal cassé simulé'));
        $cassee->shouldReceive('notifierEnseignant')->andThrow(new \RuntimeException('canal cassé simulé'));

        Etudiant::create([
            'cin' => 'NT910001', 'nom' => 'Test', 'prenom' => 'Canal',
            'email' => 'nt910001@example.com', 'sexe' => 'M',
            'faculte_id' => $this->planning->groupe->faculte_id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'Rue',
            'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'motdepasse1', 'groupe_id' => $this->planning->groupe_id,
        ]);
    }

    public function test_echec_de_notification_ne_fait_pas_perdre_la_mutation(): void
    {
        $this->notifierCase();

        $this->service->annulerCreneau($this->slot, $this->admin->id, 'test notification cassée');

        $this->assertSame('cancelled', $this->slot->fresh()->status,
            'La mutation (annulation) DOIT persister même si la notification échoue.');
        $this->assertDatabaseHas('schedule_changes', [
            'schedule_slot_id' => $this->slot->id, 'change_type' => 'cancelled',
        ]);
    }

    public function test_refus_metier_n_emet_aucun_evenement(): void
    {
        Event::fake([ScheduleChanged::class]);

        Schedule::whereKey($this->planning->id)->update(['statut' => 'verrouille']);

        try {
            $this->service->annulerCreneau($this->slot->fresh(), $this->admin->id, 'doit être refusé');
            $this->fail('DomainException attendue (planning verrouillé).');
        } catch (DomainException) {
            // attendu
        }

        Event::assertNothingDispatched(ScheduleChanged::class);
        $this->assertSame('scheduled', $this->slot->fresh()->status);
    }

    public function test_annulation_emet_un_seul_evenement_post_commit(): void
    {
        Event::fake([ScheduleChanged::class]);

        $this->service->annulerCreneau($this->slot, $this->admin->id, 'annulation normale');

        Event::assertDispatched(ScheduleChanged::class,
            fn (ScheduleChanged $e) => $e->slotId === $this->slot->id && $e->typeChangement === 'cancelled');
        // Convention prouvée : mutation = 2 émissions (type métier + « notifie »
        // d'invalidation de cache — ignorée par NotifierPlanning), ni plus.
        Event::assertDispatchedTimes(ScheduleChanged::class, 2);
        $this->assertSame('cancelled', $this->slot->fresh()->status);
    }

    public function test_restauration_et_transition_emettent_post_commit(): void
    {
        Event::fake([ScheduleChanged::class]);

        $this->service->annulerCreneau($this->slot, $this->admin->id, 'annulation');
        $this->service->restaurerCreneau($this->slot->fresh(), $this->admin->id, 'restauration');

        Event::assertDispatchedTimes(ScheduleChanged::class, 4);
        Event::assertDispatched(ScheduleChanged::class, fn (ScheduleChanged $e) => $e->typeChangement === 'restored');

        // Transition d'état : publication (planning sans conflit).
        $this->service->transiterPlanning($this->planning->fresh(), 'valide');
        // annuler (2 : cancelled+notifie) + restaurer (2) + transiter (1) = 5.
        Event::assertDispatchedTimes(ScheduleChanged::class, 5);
        Event::assertDispatched(ScheduleChanged::class, fn (ScheduleChanged $e) => $e->typeChangement === 'statut:valide');
    }

    public function test_http_annulation_ok_meme_si_notification_cassee(): void
    {
        $this->notifierCase();

        $this->actingAs($this->admin)
            ->post(route('admin.emplois-du-temps.slots.cancel', $this->slot), ['motif' => 'test'])
            ->assertRedirect();

        $this->assertSame('cancelled', $this->slot->fresh()->status);
    }
}
