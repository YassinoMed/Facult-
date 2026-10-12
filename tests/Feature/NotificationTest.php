<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\AppNotification;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Services\ScheduleService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Centre de notifications (§4-6) : création via events, compteur,
 * lecture, isolation, centre.
 */
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculte;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences', 'code' => 'FST',
            'description' => 'Sciences', 'active' => true,
        ]);
    }

    protected function creerEtudiant(string $cin): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Test', 'prenom' => 'Etudiant',
            'email' => strtolower($cin).'@example.com', 'sexe' => 'M',
            'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'Rue',
            'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'motdepasse1',
        ]);
        $etudiant->forceFill(['num_inscription' => 'INS-2026-'.$cin])->save();
        $etudiant->definirStatut('valide');

        return $etudiant->fresh();
    }

    public function test_cloche_badge_et_centre_etudiant(): void
    {
        $etudiant = $this->creerEtudiant('BK970001');
        $service = $this->app->make(NotificationService::class);
        $service->notifierEtudiant($etudiant, 'info.test', 'Titre test', 'Message test.');

        session(['etudiant_auth_id' => $etudiant->id]);

        // Badge + dropdown dans la navbar.
        $this->get(route('etudiant.dashboard'))
            ->assertOk()->assertSee('aria-label="Notifications"', false)->assertSee('1 non lue');

        // Centre + lecture.
        $notification = AppNotification::firstOrFail();
        $this->get(route('etudiant.notifications.index'))->assertOk()->assertSee('Titre test');
        $this->patch(route('etudiant.notifications.lire', $notification))->assertRedirect();
        $this->assertNotNull($notification->fresh()->read_at);

        // Tout marquer lu.
        $service->notifierEtudiant($etudiant, 'info.test', 'Autre', 'Message.');
        $this->post(route('etudiant.notifications.lire-tout'))->assertRedirect();
        $this->assertSame(0, $service->nonLuesEtudiant($etudiant->id));
    }

    public function test_isolation_lecture(): void
    {
        $etudiantA = $this->creerEtudiant('BK970002');
        $etudiantB = $this->creerEtudiant('BK970003');
        $service = $this->app->make(NotificationService::class);
        $service->notifierEtudiant($etudiantB, 'info.test', 'Privé', 'Message.');

        session(['etudiant_auth_id' => $etudiantA->id]);

        $notification = AppNotification::firstOrFail();
        $this->patch(route('etudiant.notifications.lire', $notification))->assertNotFound();
        $this->get(route('etudiant.notifications.index'))->assertOk()->assertDontSee('Privé');
    }

    public function test_admin_recoit_et_lit(): void
    {
        $admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $service = $this->app->make(NotificationService::class);
        $service->notifierUser($admin, 'info.test', 'Alerte admin', 'Message.');

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertSee('1 non lue');
        $this->actingAs($admin)->get(route('admin.notifications.index'))->assertOk()->assertSee('Alerte admin');
    }

    public function test_planning_notifie_groupe(): void
    {
        config(['modules.schedule.active' => true]);

        $groupe = Group::create([
            'code' => 'L1-N', 'nom' => 'L1 N', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'actif' => true,
        ]);
        $etudiant = $this->creerEtudiant('BK970004');
        $etudiant->forceFill(['groupe_id' => $groupe->id])->save();

        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $planning = Schedule::create([
            'annee_universitaire_id' => $annee->id, 'semestre' => 1,
            'groupe_id' => $groupe->id, 'nom' => 'Test', 'actif' => true,
        ]);
        $matiere = Matiere::create([
            'code' => 'TST101', 'nom' => 'Test', 'coefficient' => 1,
            'credits' => 1, 'niveau' => 'L1', 'semestre' => 1,
        ]);

        $service = $this->app->make(ScheduleService::class);
        $service->creerCreneau($planning, [
            'matiere_id' => $matiere->id, 'day_of_week' => 'monday',
            'start_time' => '08:00', 'end_time' => '09:30', 'type' => 'course',
        ]);

        $this->assertDatabaseHas('notifications', [
            'etudiant_id' => $etudiant->id,
            'type' => 'schedule.created',
        ]);
    }
}
