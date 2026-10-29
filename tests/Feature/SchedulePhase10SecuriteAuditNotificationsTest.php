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
use App\Modules\Schedule\Services\ScheduleSecurityAuditService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulePhase10SecuriteAuditNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculteSciences;

    protected Faculte $faculteLettres;

    protected User $adminSciences;

    protected User $superAdmin;

    protected Schedule $planningSciences;

    protected Schedule $planningLettres;

    protected Room $salleSciences;

    protected Room $salleLettres;

    protected Teacher $prof;

    protected Matiere $matiere;

    protected ScheduleSecurityAuditService $securityService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculteSciences = Faculte::create([
            'nom' => 'Faculté des Sciences P10',
            'code' => 'FS_P10_'.uniqid(),
            'actif' => true,
        ]);

        $this->faculteLettres = Faculte::create([
            'nom' => 'Faculté des Lettres P10',
            'code' => 'FL_P10_'.uniqid(),
            'actif' => true,
        ]);

        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027-P10-'.uniqid(),
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $groupeSciences = Group::create([
            'nom' => 'Groupe Sciences P10',
            'code' => 'G_SCI_'.uniqid(),
            'niveau' => 'L3',
            'capacite' => 30,
            'faculte_id' => $this->faculteSciences->id,
            'actif' => true,
        ]);

        $groupeLettres = Group::create([
            'nom' => 'Groupe Lettres P10',
            'code' => 'G_LET_'.uniqid(),
            'niveau' => 'L3',
            'capacite' => 25,
            'faculte_id' => $this->faculteLettres->id,
            'actif' => true,
        ]);

        $this->planningSciences = Schedule::create([
            'annee_universitaire_id' => $annee->id,
            'semestre' => 1,
            'groupe_id' => $groupeSciences->id,
            'nom' => 'Planning Sciences P10',
            'statut' => 'brouillon',
            'actif' => true,
        ]);

        $this->planningLettres = Schedule::create([
            'annee_universitaire_id' => $annee->id,
            'semestre' => 1,
            'groupe_id' => $groupeLettres->id,
            'nom' => 'Planning Lettres P10',
            'statut' => 'brouillon',
            'actif' => true,
        ]);

        $this->salleSciences = Room::create([
            'code' => 'S_SCI_'.uniqid(),
            'nom' => 'Salle Sciences P10',
            'capacite' => 40,
            'faculte_id' => $this->faculteSciences->id,
            'actif' => true,
        ]);

        $this->salleLettres = Room::create([
            'code' => 'S_LET_'.uniqid(),
            'nom' => 'Salle Lettres P10',
            'capacite' => 40,
            'faculte_id' => $this->faculteLettres->id,
            'actif' => true,
        ]);

        $codeMat = 'MAT_P10_'.substr(uniqid(), -6);
        $this->matiere = Matiere::create([
            'code' => $codeMat,
            'nom' => 'Physique Quantique P10',
            'niveau' => 'L3',
            'semestre' => 1,
            'faculte_id' => $this->faculteSciences->id,
        ]);

        $this->prof = Teacher::create([
            'matricule' => 'ENS-P10-'.substr(uniqid(), -6),
            'nom' => 'Ben Romdhane',
            'prenom' => 'Sami',
            'email' => 'sami.br@test.tn',
            'charge_statutaire' => 192.0,
            'qualifications' => [$codeMat],
            'actif' => true,
        ]);

        // Création des utilisateurs
        $this->adminSciences = User::factory()->create([
            'name' => 'Admin Sciences',
            'faculte_id' => $this->faculteSciences->id,
            'role' => 'admin_faculte',
            'actif' => true,
        ]);

        $this->superAdmin = User::factory()->create([
            'name' => 'Super Admin Central',
            'role' => 'super_admin',
            'actif' => true,
        ]);

        $this->securityService = app(ScheduleSecurityAuditService::class);
    }

    public function test_admin_faculte_ne_peut_pas_acceder_au_planning_d_une_autre_faculte(): void
    {
        // Admin Sciences peut accéder au planning de Sciences
        $this->assertTrue(
            $this->securityService->verifierAccesPlanning($this->adminSciences, $this->planningSciences, 'edit')
        );

        // Admin Sciences NE PEUT PAS accéder au planning de Lettres
        $this->assertFalse(
            $this->securityService->verifierAccesPlanning($this->adminSciences, $this->planningLettres, 'edit')
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Accès non autorisé');

        $this->securityService->autoriserAccesPlanning($this->adminSciences, $this->planningLettres, 'edit');
    }

    public function test_super_admin_peut_acceder_a_toutes_les_facultes(): void
    {
        $this->assertTrue(
            $this->securityService->verifierAccesPlanning($this->superAdmin, $this->planningSciences, 'publish')
        );
        $this->assertTrue(
            $this->securityService->verifierAccesPlanning($this->superAdmin, $this->planningLettres, 'publish')
        );
    }

    public function test_admin_faculte_ne_peut_pas_reserver_salle_d_une_autre_faculte(): void
    {
        $this->assertTrue(
            $this->securityService->verifierAccesRessource($this->adminSciences, $this->salleSciences)
        );

        $this->assertFalse(
            $this->securityService->verifierAccesRessource($this->adminSciences, $this->salleLettres)
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Cloisonnement multi-facultés');

        $this->securityService->autoriserAccesRessource($this->adminSciences, $this->salleLettres);
    }

    public function test_concurrence_optimiste_rejette_modification_si_empreinte_perimee(): void
    {
        $empreinteAncienne = '2026-01-01T00:00:00+00:00';

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Modification concurrente rejetée');

        $this->securityService->executerModificationSecurisee(
            $this->adminSciences,
            $this->planningSciences,
            function (Schedule $schedule) {
                $schedule->update(['nom' => 'Nouveau Nom']);
            },
            $empreinteAncienne
        );
    }

    public function test_execution_securisee_applique_modification_avec_succes(): void
    {
        $empreinteValide = $this->planningSciences->fresh()->updated_at?->toIso8601String();

        $resultat = $this->securityService->executerModificationSecurisee(
            $this->adminSciences,
            $this->planningSciences,
            function (Schedule $schedule) {
                $schedule->update(['nom' => 'Planning Sciences Modifié']);

                return true;
            },
            $empreinteValide
        );

        $this->assertTrue($resultat);
        $this->assertSame('Planning Sciences Modifié', $this->planningSciences->fresh()->nom);
    }

    public function test_notification_changement_creneau_emet_alertes_enseignant_et_etudiants(): void
    {
        $slot = ScheduleSlot::create([
            'schedule_id' => $this->planningSciences->id,
            'matiere_id' => $this->matiere->id,
            'teacher_id' => $this->prof->id,
            'room_id' => $this->salleSciences->id,
            'day_of_week' => 'monday',
            'start_time' => '08:30:00',
            'end_time' => '10:00:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $profRemplacant = Teacher::create([
            'matricule' => 'ENS-P10-REM-'.substr(uniqid(), -6),
            'nom' => 'Zitouni',
            'prenom' => 'Hela',
            'email' => 'hela.zitouni@test.tn',
            'qualifications' => [$this->matiere->code],
            'charge_statutaire' => 192.0,
            'actif' => true,
        ]);

        $notifications = $this->securityService->notifierChangementCreneau(
            $slot,
            'teacher_changed',
            'Arrêt de travail pour raison médicale',
            $profRemplacant->id
        );

        $this->assertNotEmpty($notifications);

        // L'enseignant actuel a reçu une notification
        $this->assertDatabaseHas('notifications', [
            'teacher_id' => $this->prof->id,
            'type' => 'schedule_slot_teacher_changed',
        ]);

        // L'ancien enseignant libéré a reçu une notification
        $this->assertDatabaseHas('notifications', [
            'teacher_id' => $profRemplacant->id,
            'type' => 'schedule_slot_desaffecte',
        ]);
    }
}
