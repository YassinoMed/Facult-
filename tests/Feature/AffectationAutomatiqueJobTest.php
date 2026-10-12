<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Exceptions\RunAnnuleException;
use App\Modules\Evaluation\Jobs\AffectationSurveillanceJob;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Models\Surveillance;
use App\Modules\Evaluation\Models\SurveillanceAssignmentRun;
use App\Modules\Evaluation\Services\SurveillanceEquilibrageService;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Suite de tests de l'affectation automatique asynchrone (§1-27).
 * Vérifie : Job Laravel, progression, contraintes dures, équilibrage,
 * déterminisme, sécurité, isolation faculté, idempotence et performance.
 */
class AffectationAutomatiqueJobTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $adminFaculteB;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private SessionExamen $session;

    private Examen $examen;

    private Programme $programme;

    private Matiere $matiere;

    private Room $salle1;

    private Room $salle2;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.evaluation.active' => true]);

        $this->faculteA = Faculte::create(['nom' => 'Faculté des Sciences', 'code' => 'FST', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'Faculté des Lettres', 'code' => 'FLSH', 'active' => true]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin Scolarité FST',
            'email' => 'admin.fst@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_scolarite');

        $this->adminFaculteB = User::creerCompteBackOffice([
            'name' => 'Admin Scolarité FLSH',
            'email' => 'admin.flsh@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_scolarite');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->programme = Programme::create([
            'code' => 'L-INFO',
            'nom' => 'Licence Informatique',
            'faculte_id' => $this->faculteA->id,
            'cycle' => 'licence',
            'duree_annees' => 3,
            'actif' => true,
        ]);

        $this->session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS-P-2027',
            'nom' => 'Session Principale Janvier 2027',
            'type' => 'principale',
            'statut' => 'open',
            'date_debut' => '2027-01-10',
            'date_fin' => '2027-01-20',
        ]);

        $this->examen = Examen::create([
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'libelle' => 'Examens S1',
            'type' => 'examen',
            'statut' => 'scheduled',
        ]);

        $this->matiere = Matiere::create([
            'code' => 'MATH101',
            'nom' => 'Analyse Mathématique',
            'niveau' => 'L1',
            'semestre' => 1,
            'coefficient' => 2,
            'credits' => 5,
        ]);

        $this->salle1 = Room::create([
            'code' => 'AMPHI-1',
            'capacite' => 120,
            'faculte_id' => $this->faculteA->id,
            'actif' => true,
        ]);

        $this->salle2 = Room::create([
            'code' => 'SALLE-201',
            'capacite' => 60,
            'faculte_id' => $this->faculteA->id,
            'actif' => true,
        ]);
    }

    private function creerEpreuve(array $attributs = []): Epreuve
    {
        return Epreuve::create(array_merge([
            'code' => 'EPR-'.uniqid(),
            'examen_id' => $this->examen->id,
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->matiere->id,
            'salle_id' => $this->salle1->id,
            'date' => '2027-01-11',
            'heure_debut' => '09:00:00',
            'heure_fin' => '11:00:00',
            'duree_minutes' => 120,
            'type_evaluation' => 'examen',
            'capacite_necessaire' => 50,
            'statut' => 'scheduled',
        ], $attributs));
    }

    /**
     * @return array<int, Teacher>
     */
    private function creerEnseignants(int $nombre, ?int $faculteId = null): array
    {
        $dep = Departement::create([
            'code' => 'DEP-'.uniqid(),
            'nom' => 'Département Scientifique',
            'faculte_id' => $faculteId ?? $this->faculteA->id,
        ]);

        $liste = [];
        for ($i = 1; $i <= $nombre; $i++) {
            $liste[] = Teacher::create([
                'matricule' => 'ENS-'.uniqid(),
                'nom' => "Nom{$i}",
                'prenom' => "Prenom{$i}",
                'email' => "ens{$i}.".uniqid().'@univ.test',
                'departement_id' => $dep->id,
                'actif' => true,
                'statut' => 'actif',
            ]);
        }

        return $liste;
    }

    public function test_dispatch_affectation_automatique_cree_un_run_et_dispatche_le_job(): void
    {
        Queue::fake();

        $this->creerEpreuve();
        $this->creerEnseignants(6);

        $response = $this->actingAs($this->admin)->postJson(route('admin.evaluations.affectations.auto'), [
            'session_id' => $this->session->id,
        ]);

        $response->assertStatus(202);
        $response->assertJsonStructure([
            'success',
            'message',
            'run' => ['id', 'uuid', 'session_examen_id', 'status', 'progress'],
        ]);

        $this->assertDatabaseHas('surveillance_assignment_runs', [
            'session_examen_id' => $this->session->id,
            'status' => SurveillanceAssignmentRun::STATUS_PENDING,
            'user_id' => $this->admin->id,
        ]);

        Queue::assertPushed(AffectationSurveillanceJob::class, function ($job) {
            return $job->sessionId === $this->session->id
                && $job->userId === $this->admin->id
                && $job->mode === 'auto';
        });
    }

    public function test_idempotence_deux_lancements_simultanes_sont_rejetes(): void
    {
        Queue::fake();

        $this->creerEpreuve();

        $runExistant = SurveillanceAssignmentRun::create([
            'uuid' => 'run-existant-uuid',
            'session_examen_id' => $this->session->id,
            'faculte_id' => null,
            'user_id' => $this->admin->id,
            'status' => SurveillanceAssignmentRun::STATUS_RUNNING,
            'mode' => 'auto',
            'progress' => 45,
            'total_epreuves' => 10,
            'epreuves_traitees' => 4,
            'affectations_creees' => 8,
            'affectations_modifiees' => 0,
            'conflits' => 0,
            'erreurs' => 0,
        ]);

        $response = $this->actingAs($this->admin)->postJson(route('admin.evaluations.affectations.auto'), [
            'session_id' => $this->session->id,
        ]);

        $response->assertStatus(409);
        $response->assertJson([
            'success' => false,
            'run' => ['id' => $runExistant->id],
        ]);

        // Aucun nouveau job dispatché
        Queue::assertNothingPushed();
    }

    public function test_job_execute_avec_succes_cree_affectations_et_notifie(): void
    {
        $this->creerEpreuve(['capacite_necessaire' => 40]);
        $this->creerEpreuve([
            'code' => 'EPR-2',
            'date' => '2027-01-12',
            'heure_debut' => '14:00:00',
            'heure_fin' => '16:00:00',
            'capacite_necessaire' => 40,
        ]);
        $this->creerEnseignants(8);

        $run = SurveillanceAssignmentRun::create([
            'uuid' => 'uuid-test-1',
            'session_examen_id' => $this->session->id,
            'faculte_id' => $this->faculteA->id,
            'user_id' => $this->admin->id,
            'status' => SurveillanceAssignmentRun::STATUS_PENDING,
            'mode' => 'auto',
            'progress' => 0,
            'total_epreuves' => 2,
            'epreuves_traitees' => 0,
            'affectations_creees' => 0,
            'affectations_modifiees' => 0,
            'conflits' => 0,
            'erreurs' => 0,
        ]);

        AffectationSurveillanceJob::dispatchSync($this->session->id, $this->faculteA->id, $this->admin->id, $run->id, 'auto');

        $run->refresh();
        $this->assertContains($run->status, [
            SurveillanceAssignmentRun::STATUS_COMPLETED,
            SurveillanceAssignmentRun::STATUS_COMPLETED_WITH_WARNINGS,
        ]);
        $this->assertSame(100, $run->progress);
        $this->assertSame(2, $run->epreuves_traitees);
        $this->assertGreaterThanOrEqual(4, $run->affectations_creees);
        $this->assertNotNull($run->finished_at);
        $this->assertNotNull($run->result_summary);

        // Des surveillances réelles ont été créées
        $this->assertGreaterThanOrEqual(4, Surveillance::whereIn('statut', ['assigne', 'confirme'])->count());

        // L'utilisateur est notifié
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->admin->id,
            'type' => 'surveillance.affectation_terminee',
        ]);
    }

    public function test_job_respecte_contrainte_dure_enseignant_inactif(): void
    {
        $this->creerEpreuve();
        $actifs = $this->creerEnseignants(6);

        $dep = $actifs[0]->departement;
        $inactif = Teacher::create([
            'matricule' => 'INACTIF-001',
            'nom' => 'Inactif',
            'prenom' => 'Prof',
            'email' => 'inactif@univ.test',
            'departement_id' => $dep->id,
            'actif' => false,
            'statut' => 'inactif',
        ]);

        $run = SurveillanceAssignmentRun::create([
            'uuid' => 'uuid-test-inactif',
            'session_examen_id' => $this->session->id,
            'faculte_id' => $this->faculteA->id,
            'user_id' => $this->admin->id,
            'status' => SurveillanceAssignmentRun::STATUS_PENDING,
            'mode' => 'auto',
            'progress' => 0,
            'total_epreuves' => 1,
            'epreuves_traitees' => 0,
            'affectations_creees' => 0,
            'affectations_modifiees' => 0,
            'conflits' => 0,
            'erreurs' => 0,
        ]);

        AffectationSurveillanceJob::dispatchSync($this->session->id, $this->faculteA->id, $this->admin->id, $run->id, 'auto');

        // L'enseignant inactif n'est JAMAIS affecté
        $this->assertFalse(Surveillance::where('teacher_id', $inactif->id)->exists());
    }

    public function test_job_respecte_contrainte_dure_pas_de_double_affectation_simultanee(): void
    {
        // 2 épreuves sur le même créneau horaire
        $e1 = $this->creerEpreuve(['salle_id' => $this->salle1->id, 'date' => '2027-01-11', 'heure_debut' => '09:00:00', 'heure_fin' => '11:00:00']);
        $e2 = $this->creerEpreuve(['salle_id' => $this->salle2->id, 'date' => '2027-01-11', 'heure_debut' => '09:00:00', 'heure_fin' => '11:00:00']);

        $this->creerEnseignants(10);

        $run = SurveillanceAssignmentRun::create([
            'uuid' => 'uuid-simultane',
            'session_examen_id' => $this->session->id,
            'faculte_id' => $this->faculteA->id,
            'user_id' => $this->admin->id,
            'status' => SurveillanceAssignmentRun::STATUS_PENDING,
            'mode' => 'auto',
            'progress' => 0,
            'total_epreuves' => 2,
            'epreuves_traitees' => 0,
            'affectations_creees' => 0,
            'affectations_modifiees' => 0,
            'conflits' => 0,
            'erreurs' => 0,
        ]);

        AffectationSurveillanceJob::dispatchSync($this->session->id, $this->faculteA->id, $this->admin->id, $run->id, 'auto');

        $surveillantsE1 = Surveillance::where('epreuve_id', $e1->id)->pluck('teacher_id')->all();
        $surveillantsE2 = Surveillance::where('epreuve_id', $e2->id)->pluck('teacher_id')->all();

        // L'intersection des enseignants affectés simultanément doit être vide
        $intersection = array_intersect($surveillantsE1, $surveillantsE2);
        $this->assertEmpty($intersection, 'Un enseignant a été affecté à 2 épreuves simultanées !');
    }

    public function test_job_respecte_contrainte_dure_conflit_schedule_slot(): void
    {
        // Épreuve le lundi 2027-01-11 de 09:00 à 11:00
        $epreuve = $this->creerEpreuve(['date' => '2027-01-11', 'heure_debut' => '09:00:00', 'heure_fin' => '11:00:00']);
        $enseignants = $this->creerEnseignants(6);
        $enseignantOccupe = $enseignants[0];

        // Créer un ScheduleSlot EDT pour cet enseignant le lundi (monday) de 09:00 à 11:00
        $groupe = Group::create([
            'code' => 'GRP-A',
            'nom' => 'Groupe A',
            'niveau' => 'L1',
            'faculte_id' => $this->faculteA->id,
            'actif' => true,
        ]);

        $schedule = Schedule::create([
            'annee_universitaire_id' => $this->annee->id,
            'semestre' => 1,
            'groupe_id' => $groupe->id,
            'nom' => 'EDT S1',
            'statut' => 'publie',
            'actif' => true,
        ]);

        ScheduleSlot::create([
            'schedule_id' => $schedule->id,
            'group_id' => $groupe->id,
            'teacher_id' => $enseignantOccupe->id,
            'room_id' => $this->salle2->id,
            'matiere_id' => $this->matiere->id,
            'day_of_week' => 'monday',
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'status' => 'scheduled',
        ]);

        $run = SurveillanceAssignmentRun::create([
            'uuid' => 'uuid-edt-slot',
            'session_examen_id' => $this->session->id,
            'faculte_id' => $this->faculteA->id,
            'user_id' => $this->admin->id,
            'status' => SurveillanceAssignmentRun::STATUS_PENDING,
            'mode' => 'auto',
            'progress' => 0,
            'total_epreuves' => 1,
            'epreuves_traitees' => 0,
            'affectations_creees' => 0,
            'affectations_modifiees' => 0,
            'conflits' => 0,
            'erreurs' => 0,
        ]);

        AffectationSurveillanceJob::dispatchSync($this->session->id, $this->faculteA->id, $this->admin->id, $run->id, 'auto');

        // L'enseignant avec cours à l'EDT ne doit pas être affecté à cette épreuve
        $this->assertFalse(
            Surveillance::where('epreuve_id', $epreuve->id)->where('teacher_id', $enseignantOccupe->id)->exists(),
            'Un enseignant avec cours régulier EDT a été affecté à l’épreuve !'
        );
    }

    public function test_job_respecte_contrainte_dure_isolation_faculte(): void
    {
        $epreuve = $this->creerEpreuve();

        // Enseignants en Faculté B
        $enseignantsFaculteB = $this->creerEnseignants(8, $this->faculteB->id);
        // Enseignants en Faculté A
        $this->creerEnseignants(8, $this->faculteA->id);

        $run = SurveillanceAssignmentRun::create([
            'uuid' => 'uuid-faculte-isolation',
            'session_examen_id' => $this->session->id,
            'faculte_id' => $this->faculteA->id,
            'user_id' => $this->admin->id,
            'status' => SurveillanceAssignmentRun::STATUS_PENDING,
            'mode' => 'auto',
            'progress' => 0,
            'total_epreuves' => 1,
            'epreuves_traitees' => 0,
            'affectations_creees' => 0,
            'affectations_modifiees' => 0,
            'conflits' => 0,
            'erreurs' => 0,
        ]);

        AffectationSurveillanceJob::dispatchSync($this->session->id, $this->faculteA->id, $this->admin->id, $run->id, 'auto');

        $idsFaculteB = collect($enseignantsFaculteB)->pluck('id')->all();
        $fuite = Surveillance::where('epreuve_id', $epreuve->id)->whereIn('teacher_id', $idsFaculteB)->exists();

        $this->assertFalse($fuite, 'Fuite inter-facultés : un enseignant de FLSH a été affecté à FST !');
    }

    public function test_job_echoue_proprement_si_enseignants_insuffisants(): void
    {
        $this->creerEpreuve(['capacite_necessaire' => 100]);
        // 1 seul enseignant alors qu'il faut au minimum 2 actifs + 1 garde
        $this->creerEnseignants(1);

        $run = SurveillanceAssignmentRun::create([
            'uuid' => 'uuid-insuffisant',
            'session_examen_id' => $this->session->id,
            'faculte_id' => $this->faculteA->id,
            'user_id' => $this->admin->id,
            'status' => SurveillanceAssignmentRun::STATUS_PENDING,
            'mode' => 'auto',
            'progress' => 0,
            'total_epreuves' => 1,
            'epreuves_traitees' => 0,
            'affectations_creees' => 0,
            'affectations_modifiees' => 0,
            'conflits' => 0,
            'erreurs' => 0,
        ]);

        AffectationSurveillanceJob::dispatchSync($this->session->id, $this->faculteA->id, $this->admin->id, $run->id, 'auto');

        $run->refresh();
        $this->assertSame(SurveillanceAssignmentRun::STATUS_FAILED, $run->status);
        $this->assertNotNull($run->error_message);
        $this->assertStringContainsString('déficit', mb_strtolower($run->error_message));

        // L'utilisateur est notifié de l'échec
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->admin->id,
            'type' => 'surveillance.affectation_impossible',
        ]);
    }

    public function test_job_determinisme_produit_le_meme_resultat(): void
    {
        $this->creerEpreuve(['code' => 'EPR-DET-1', 'date' => '2027-01-11', 'capacite_necessaire' => 40]);
        $this->creerEpreuve(['code' => 'EPR-DET-2', 'date' => '2027-01-12', 'capacite_necessaire' => 40]);
        $this->creerEnseignants(8);

        $run1 = SurveillanceAssignmentRun::create([
            'uuid' => 'uuid-det-1',
            'session_examen_id' => $this->session->id,
            'faculte_id' => $this->faculteA->id,
            'user_id' => $this->admin->id,
            'status' => SurveillanceAssignmentRun::STATUS_PENDING,
            'mode' => 'auto',
            'progress' => 0,
            'total_epreuves' => 2,
            'epreuves_traitees' => 0,
            'affectations_creees' => 0,
            'affectations_modifiees' => 0,
            'conflits' => 0,
            'erreurs' => 0,
        ]);

        AffectationSurveillanceJob::dispatchSync($this->session->id, $this->faculteA->id, $this->admin->id, $run1->id, 'auto');

        $affectationsRun1 = Surveillance::orderBy('epreuve_id')->orderBy('teacher_id')->get(['epreuve_id', 'teacher_id', 'role'])->toArray();

        // Supprimer les affectations pour rejouer avec exactement les mêmes données
        Surveillance::query()->delete();

        $run2 = SurveillanceAssignmentRun::create([
            'uuid' => 'uuid-det-2',
            'session_examen_id' => $this->session->id,
            'faculte_id' => $this->faculteA->id,
            'user_id' => $this->admin->id,
            'status' => SurveillanceAssignmentRun::STATUS_PENDING,
            'mode' => 'auto',
            'progress' => 0,
            'total_epreuves' => 2,
            'epreuves_traitees' => 0,
            'affectations_creees' => 0,
            'affectations_modifiees' => 0,
            'conflits' => 0,
            'erreurs' => 0,
        ]);

        AffectationSurveillanceJob::dispatchSync($this->session->id, $this->faculteA->id, $this->admin->id, $run2->id, 'auto');

        $affectationsRun2 = Surveillance::orderBy('epreuve_id')->orderBy('teacher_id')->get(['epreuve_id', 'teacher_id', 'role'])->toArray();

        $this->assertSame($affectationsRun1, $affectationsRun2, 'Le moteur d’affectation n’est pas déterministe !');
    }

    public function test_securite_utilisateur_sans_permission_refuse(): void
    {
        $userSansDroit = User::factory()->create();

        $response = $this->actingAs($userSansDroit)->post(route('admin.evaluations.affectations.auto'), [
            'session_id' => $this->session->id,
        ]);

        $response->assertStatus(403);
    }

    public function test_securite_idor_run_status_autre_faculte_refuse(): void
    {
        $adminFaculteA = User::creerCompteBackOffice([
            'name' => 'Admin FST Restreint',
            'email' => 'admin.fst.restreint@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $runFaculteB = SurveillanceAssignmentRun::create([
            'uuid' => 'uuid-flsh',
            'session_examen_id' => $this->session->id,
            'faculte_id' => $this->faculteB->id,
            'user_id' => $this->adminFaculteB->id,
            'status' => SurveillanceAssignmentRun::STATUS_COMPLETED,
            'mode' => 'auto',
            'progress' => 100,
            'total_epreuves' => 5,
            'epreuves_traitees' => 5,
            'affectations_creees' => 10,
            'affectations_modifiees' => 0,
            'conflits' => 0,
            'erreurs' => 0,
        ]);

        // L'admin de Faculté A tente d'accéder au run de Faculté B -> 404
        $response = $this->actingAs($adminFaculteA)->getJson(route('admin.evaluations.affectations.runs.status', $runFaculteB));
        $response->assertStatus(404);
    }

    public function test_polling_endpoints_status_et_actif(): void
    {
        $run = SurveillanceAssignmentRun::create([
            'uuid' => 'uuid-poll',
            'session_examen_id' => $this->session->id,
            'faculte_id' => $this->faculteA->id,
            'user_id' => $this->admin->id,
            'status' => SurveillanceAssignmentRun::STATUS_RUNNING,
            'mode' => 'auto',
            'progress' => 65,
            'total_epreuves' => 20,
            'epreuves_traitees' => 13,
            'affectations_creees' => 26,
            'affectations_modifiees' => 0,
            'conflits' => 1,
            'erreurs' => 0,
            'started_at' => now()->subSeconds(8),
        ]);

        // 1. Consultation du statut du run
        $response = $this->actingAs($this->admin)->getJson(route('admin.evaluations.affectations.runs.status', $run));
        $response->assertOk();
        $response->assertJson([
            'id' => $run->id,
            'uuid' => 'uuid-poll',
            'status' => SurveillanceAssignmentRun::STATUS_RUNNING,
            'progress' => 65,
            'epreuves_traitees' => 13,
            'affectations_creees' => 26,
            'conflits' => 1,
            'is_terminated' => false,
        ]);

        // 2. Consultation du run actif pour la session
        $responseActif = $this->actingAs($this->admin)->getJson(route('admin.evaluations.affectations.runs.actif', ['session_id' => $this->session->id]));
        $responseActif->assertOk();
        $responseActif->assertJson([
            'run' => [
                'id' => $run->id,
                'status' => SurveillanceAssignmentRun::STATUS_RUNNING,
            ],
        ]);
    }

    public function test_performance_absence_de_n_plus_un(): void
    {
        // 5 épreuves et 15 enseignants
        for ($i = 1; $i <= 5; $i++) {
            $this->creerEpreuve([
                'code' => "EPR-PERF-{$i}",
                'date' => "2027-01-1{$i}",
                'capacite_necessaire' => 40,
            ]);
        }
        $this->creerEnseignants(15);

        $run = SurveillanceAssignmentRun::create([
            'uuid' => 'uuid-perf',
            'session_examen_id' => $this->session->id,
            'faculte_id' => $this->faculteA->id,
            'user_id' => $this->admin->id,
            'status' => SurveillanceAssignmentRun::STATUS_PENDING,
            'mode' => 'auto',
            'progress' => 0,
            'total_epreuves' => 5,
            'epreuves_traitees' => 0,
            'affectations_creees' => 0,
            'affectations_modifiees' => 0,
            'conflits' => 0,
            'erreurs' => 0,
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $debut = microtime(true);
        AffectationSurveillanceJob::dispatchSync($this->session->id, $this->faculteA->id, $this->admin->id, $run->id, 'auto');
        $duree = microtime(true) - $debut;

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $run->refresh();
        $this->assertContains($run->status, [
            SurveillanceAssignmentRun::STATUS_COMPLETED,
            SurveillanceAssignmentRun::STATUS_COMPLETED_WITH_WARNINGS,
        ]);
        $this->assertLessThan(2.0, $duree, 'L’affectation par lots a pris plus de 2 secondes.');
        $this->assertLessThan(250, count($queries), 'Nombre de requêtes excessif (N+1 potentiel).');
    }

    public function test_annulation_cooperative_entre_epreuves(): void
    {
        $e1 = $this->creerEpreuve(['code' => 'EPR-CANCEL-1', 'date' => '2027-01-11']);
        $this->creerEpreuve(['code' => 'EPR-CANCEL-2', 'date' => '2027-01-12']);
        $this->creerEnseignants(6);

        $service = app(SurveillanceEquilibrageService::class);
        $simulation = $service->simulerSession($this->session, ['faculte_id' => $this->faculteA->id]);
        $this->assertNotSame('IMPOSSIBLE', $simulation['statut']);

        // Annulation après la 1re épreuve : traverse sans avertissement.
        $avancement = function () {
            static $appels = 0;
            $appels++;
            if ($appels >= 1) {
                throw new RunAnnuleException;
            }
        };

        try {
            $service->appliquerSessionParLots($this->session, $simulation, $this->admin->id, $avancement);
            $this->fail('Interruption coopérative attendue.');
        } catch (RunAnnuleException) {
            $this->assertTrue(true);
        }

        // 1re épreuve commise, 2e jamais touchée (pas de rollback global).
        $this->assertGreaterThan(0, Surveillance::where('epreuve_id', $e1->id)->whereIn('statut', ['assigne', 'confirme'])->count());
    }

    public function test_run_annule_avant_execution_noop(): void
    {
        $this->creerEpreuve(['code' => 'EPR-CANCEL-0']);
        $this->creerEnseignants(6);

        $run = SurveillanceAssignmentRun::create([
            'uuid' => 'uuid-cancel-noop',
            'session_examen_id' => $this->session->id,
            'faculte_id' => $this->faculteA->id,
            'user_id' => $this->admin->id,
            'status' => SurveillanceAssignmentRun::STATUS_CANCELLED,
            'mode' => 'auto',
        ]);

        AffectationSurveillanceJob::dispatchSync($this->session->id, $this->faculteA->id, $this->admin->id, $run->id, 'auto');

        $this->assertSame(
            SurveillanceAssignmentRun::STATUS_CANCELLED,
            $run->fresh()->status
        );
        $this->assertSame(0, Surveillance::whereIn('statut', ['assigne', 'confirme'])->count());
    }
}
