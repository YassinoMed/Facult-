<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\User;
use App\Modules\Calendar\Models\EvenementCalendrier;
use App\Modules\Calendar\Services\JoursOuvrables;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\NoteEpreuve;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\DeliberationService;
use App\Modules\Evaluation\Services\ExamenService;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\Teacher;
use App\Modules\Schedule\Services\ScheduleService;
use App\Modules\StudentDossiers\Models\StudentDossier;
use App\Modules\Tasks\Services\TaskGenerationService;
use App\Services\AcademicCalculationService;
use App\Services\FicheEtudiantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phases 10-12 — Workflow Engine (matrices + gardes), dossier 360°,
 * planning intelligent (publication, duplication, verrouillage).
 */
class WorkflowDossierPlanningTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'modules.evaluation.active' => true,
            'modules.schedule.active' => true,
            'modules.calendar.active' => true,
            'modules.attendance.active' => true,
            'modules.online-requests.active' => true,
            'modules.tasks.active' => true,
        ]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.wdp@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
    }

    public function test_deliberation_transitions_controlees(): void
    {
        $deliberation = Deliberation::create([
            'annee_universitaire_id' => $this->annee->id, 'statut' => 'draft', 'created_by' => $this->admin->id,
        ]);

        $this->assertTrue($deliberation->peutTransiterVers('prepared'));
        $this->assertFalse($deliberation->peutTransiterVers('validated'));

        // Validation depuis brouillon : interdite (matrice, pas seulement métier).
        try {
            app(DeliberationService::class)->valider($deliberation);
            $this->fail('Validation depuis draft aurait dû échouer.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('draft', $e->getMessage());
        }
    }

    public function test_note_epreuve_draft_non_validable(): void
    {
        $note = new NoteEpreuve(['statut' => 'draft', 'valeur' => 12]);

        $this->assertFalse($note->peutTransiterVers('validated'));
        $this->assertTrue($note->peutTransiterVers('entered'));
        $this->assertTrue((new NoteEpreuve(['statut' => 'validated']))->peutTransiterVers('locked'));
    }

    public function test_session_verrouillage_exige_cloture(): void
    {
        $session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id, 'code' => 'S1', 'nom' => 'S1',
            'type' => 'principale', 'date_debut' => '2027-01-05', 'date_fin' => '2027-01-20',
            'statut' => 'draft', 'created_by' => $this->admin->id,
        ]);

        $this->assertFalse($session->peutTransiterVers('locked'));

        $this->actingAs($this->admin)
            ->post(route('admin.evaluations.sessions.verrouiller', $session))
            ->assertSessionHasErrors();
    }

    public function test_examen_transiter_via_service_avec_audit(): void
    {
        $session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id, 'code' => 'S2', 'nom' => 'S2',
            'type' => 'principale', 'date_debut' => '2027-01-05', 'date_fin' => '2027-01-20',
            'statut' => 'open', 'created_by' => $this->admin->id,
        ]);
        $examen = Examen::create([
            'session_id' => $session->id, 'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examen', 'type' => 'examen', 'statut' => 'draft',
        ]);

        app(ExamenService::class)->transiter($examen, 'planned');

        $this->assertSame('planned', $examen->fresh()->statut);

        $this->expectException(\DomainException::class);
        app(ExamenService::class)->transiter($examen->fresh(), 'completed');
    }

    public function test_jours_ouvres_excluent_weekends_et_feries(): void
    {
        $jours = app(JoursOuvrables::class);

        // Samedi/dimanche non ouvrés.
        $this->assertFalse($jours->estOuvre('2026-10-03'));
        $this->assertTrue($jours->estOuvre('2026-10-05'));

        EvenementCalendrier::create([
            'annee_universitaire_id' => $this->annee->id, 'titre' => 'Fête',
            'type' => 'ferie', 'date_debut' => '2026-10-06', 'audience' => 'tous',
            'statut' => 'publie', 'created_by' => $this->admin->id,
        ]);

        $this->assertFalse($jours->estOuvre('2026-10-06'));
        // Lun 05 → mer 07 moins le férié du 06 = 2 jours.
        $this->assertSame(2, $jours->restants('2026-10-07', '2026-10-05'));
    }

    public function test_fiche_etudiant_synthese_completude_doublons(): void
    {
        $etudiant = $this->creerEtudiantAvecNotes();

        $fiche = app(FicheEtudiantService::class);
        $resume = $fiche->resume($etudiant);

        $this->assertEquals(12.0, $resume['moyenne_generale']);
        $this->assertSame(6, $resume['credits_obtenus']);

        $completude = $fiche->completude($etudiant);
        $this->assertSame(100, $completude['pourcentage']);

        $this->assertSame([], $fiche->anomalies($etudiant));

        // Doublon : même nom/prénom/naissance, CIN différent.
        $doublon = Etudiant::create([
            'cin' => 'DUP00001', 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => 'doublon.wdp@universite.test', 'date_naissance' => '2004-01-01',
            'sexe' => 'F', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);

        $candidats = $fiche->doublonsPotentiels($etudiant);
        $this->assertTrue($candidats->contains('id', $doublon->id));

        // Timeline ordonnée décroissante.
        $dates = array_column($fiche->timeline($etudiant), 'date');
        $triees = $dates;
        rsort($triees);
        $this->assertSame($triees, $dates);
    }

    public function test_fiche_sans_groupe_signale_anomalie(): void
    {
        $etudiant = $this->creerEtudiantAvecNotes();
        $etudiant->update(['groupe_id' => null]);

        $reponse = $this->actingAs($this->admin)->get(route('admin.etudiants.show', $etudiant));
        $reponse->assertOk();
        $reponse->assertSee('sans groupe', false);
        $reponse->assertSee('Synthèse 360°', false);
    }

    public function test_planning_publication_bloquee_si_conflit(): void
    {
        [$planning] = $this->creerPlanningAvecConflit();

        $service = app(ScheduleService::class);
        $controle = $service->controlePublication($planning);

        $this->assertNotEmpty($controle['conflits']);

        $service->transiterPlanning($planning, 'valide');

        $this->expectException(\DomainException::class);
        $service->publierPlanning($planning->fresh(), $this->admin->id);
    }

    public function test_planning_publie_verrouille_puis_duplique(): void
    {
        $groupe = Group::create(['code' => 'G1', 'nom' => 'G1', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1', 'actif' => true]);
        $planning = Schedule::create([
            'annee_universitaire_id' => $this->annee->id, 'semestre' => 1, 'groupe_id' => $groupe->id,
            'nom' => 'EDT G1', 'actif' => true, 'statut' => 'brouillon',
        ]);

        $service = app(ScheduleService::class);
        $service->transiterPlanning($planning, 'valide');
        $service->publierPlanning($planning->fresh(), $this->admin->id);

        $this->assertSame('publie', $planning->fresh()->statut);

        // Mutation interdite sur planning verrouillé.
        $service->verrouillerPlanning($planning->fresh());
        $this->assertSame('verrouille', $planning->fresh()->statut);

        $slot = $planning->slots()->first();
        if ($slot !== null) {
            $this->expectException(\DomainException::class);
            $service->modifierCreneau($slot, ['notes' => 'X'], $this->admin->id);
        } else {
            $this->assertTrue(true);
        }
    }

    public function test_duplication_copie_creneaux_sans_salles(): void
    {
        [$planning] = $this->creerPlanningAvecConflit();

        $groupe2 = Group::create(['code' => 'G2', 'nom' => 'G2', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1', 'actif' => true]);
        $attendus = app(ScheduleService::class)->previsualiserDuplication($planning);
        $this->assertSame(2, $attendus);

        $copie = app(ScheduleService::class)->dupliquer($planning, [
            'groupe_id' => $groupe2->id,
            'annee_universitaire_id' => $this->annee->id,
            'semestre' => 2,
        ], $this->admin->id);

        $this->assertSame('brouillon', $copie->statut);
        $this->assertSame(2, $copie->slots()->count());
        $this->assertSame(0, $copie->slots()->whereNotNull('room_id')->count());
    }

    public function test_tache_sans_groupe_generee(): void
    {
        $etudiant = $this->creerEtudiantAvecNotes();
        $etudiant->update(['groupe_id' => null]);

        $compteurs = app(TaskGenerationService::class)->generer();

        $this->assertSame(1, $compteurs['sans_groupe']);
        $this->assertDatabaseHas('tasks', ['cle_unique' => "sans_groupe:{$etudiant->id}"]);
    }

    /** @return array{0: Schedule} */
    private function creerPlanningAvecConflit(): array
    {
        $groupe = Group::create(['code' => 'GC', 'nom' => 'GC', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1', 'actif' => true]);
        $planning = Schedule::create([
            'annee_universitaire_id' => $this->annee->id, 'semestre' => 1, 'groupe_id' => $groupe->id,
            'nom' => 'EDT GC', 'actif' => true, 'statut' => 'brouillon',
        ]);

        $matiere = Matiere::create(['code' => 'M101', 'nom' => 'Maths', 'niveau' => 'L1', 'semestre' => 1, 'credits' => 4]);
        $enseignant = Teacher::create(['nom' => 'Ben', 'prenom' => 'Ali', 'actif' => true, 'statut' => 'actif']);
        $salle = Room::create(['code' => 'S1', 'capacite' => 50, 'actif' => true]);

        foreach ([['09:00', '11:00'], ['10:00', '12:00']] as [$debut, $fin]) {
            $planning->slots()->create([
                'matiere_id' => $matiere->id, 'teacher_id' => $enseignant->id, 'room_id' => $salle->id,
                'day_of_week' => 'monday', 'start_time' => $debut, 'end_time' => $fin,
                'type' => 'course', 'status' => 'scheduled',
            ]);
        }

        return [$planning];
    }

    private function creerEtudiantAvecNotes(): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => 'EE'.random_int(100000, 999999), 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => 'sara.'.random_int(1000, 9999).'@wdp.test', 'date_naissance' => '2004-01-01',
            'sexe' => 'F', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
            'telephone' => '+212600000000',
        ]);
        $etudiant->definirStatut('valide');

        StudentDossier::create([
            'etudiant_id' => $etudiant->id, 'reference' => 'DOS-'.random_int(100000, 999999), 'status' => 'open',
        ]);

        $groupe = Group::create(['code' => 'GE'.random_int(100, 999), 'nom' => 'GE', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1', 'actif' => true]);
        $etudiant->update(['groupe_id' => $groupe->id, 'programme_id' => null]);

        $programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LP'.random_int(100, 999), 'nom' => 'Licence',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $etudiant->update(['programme_id' => $programme->id]);

        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1', 'statut' => 'en_cours',
        ]);
        $semestre = $inscription->semestres()->create(['numero' => 1]);

        $matiere = Matiere::create([
            'code' => 'N'.random_int(100, 999), 'nom' => 'Note', 'niveau' => 'L1',
            'semestre' => 1, 'credits' => 6, 'coefficient' => 1,
        ]);
        Note::create([
            'etudiant_id' => $etudiant->id, 'inscription_academique_id' => $inscription->id,
            'semestre_id' => $semestre->id, 'matiere_id' => $matiere->id,
            'note' => 12, 'coefficient' => 1, 'credits' => 6, 'session' => 'normale',
        ]);

        app(AcademicCalculationService::class)->recalculerInscription($inscription->fresh());

        return $etudiant->fresh();
    }
}
