<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Batiment;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleAvailability;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use App\Modules\Schedule\Models\TimetableConstraint;
use App\Modules\Schedule\Services\ScheduleAuditReport;
use App\Modules\Schedule\Services\ScheduleConstraintChecker;
use App\Modules\Schedule\Services\ScheduleConstraintRegistry;
use App\Modules\Schedule\Services\ScheduleService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests exhaustifs de PHASE 3 : Registre des contraintes et vérificateur indépendant.
 */
class SchedulePhase3RegistreContraintesCheckerTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Batiment $batimentA;

    private Batiment $batimentB;

    private Room $salleStandard;

    private Room $grandAmphi;

    private Room $salleBatB;

    private Group $groupe;

    private Teacher $enseignantTitulaire;

    private Teacher $enseignantSecondaire;

    private Matiere $matiereAlgo;

    private Matiere $matiereReseaux;

    private Schedule $schedule;

    private ScheduleConstraintRegistry $registry;

    private ScheduleConstraintChecker $checker;

    private ScheduleService $scheduleService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = app(ScheduleConstraintRegistry::class);
        $this->checker = app(ScheduleConstraintChecker::class);
        $this->scheduleService = app(ScheduleService::class);

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences et Techniques',
            'code' => 'FST_P3',
            'actif' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027-P3',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->batimentA = Batiment::create([
            'nom' => 'Bâtiment Algorithmes',
            'code' => 'BAT_A_P3',
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->batimentB = Batiment::create([
            'nom' => 'Bâtiment Réseaux',
            'code' => 'BAT_B_P3',
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->salleStandard = Room::create([
            'code' => 'S101_P3',
            'nom' => 'Salle Standard 101',
            'capacite' => 40,
            'actif' => true,
            'faculte_id' => $this->faculte->id,
            'batiment_id' => $this->batimentA->id,
            'heure_ouverture' => '07:30:00',
            'heure_fermeture' => '19:30:00',
            'equipements_fixes' => ['videoprojecteur'],
        ]);

        $this->grandAmphi = Room::create([
            'code' => 'AMPHI_MAX_P3',
            'nom' => 'Grand Amphithéâtre Turing',
            'capacite' => 200,
            'actif' => true,
            'faculte_id' => $this->faculte->id,
            'batiment_id' => $this->batimentA->id,
            'heure_ouverture' => '07:30:00',
            'heure_fermeture' => '20:00:00',
            'equipements_fixes' => ['videoprojecteur', 'sonorisation'],
        ]);

        $this->salleBatB = Room::create([
            'code' => 'SB201_P3',
            'nom' => 'Salle Bat B 201',
            'capacite' => 35,
            'actif' => true,
            'faculte_id' => $this->faculte->id,
            'batiment_id' => $this->batimentB->id,
            'heure_ouverture' => '08:00:00',
            'heure_fermeture' => '18:00:00',
            'equipements_fixes' => [],
        ]);

        $this->groupe = Group::create([
            'code' => 'L2_G1_P3',
            'nom' => 'Groupe L2 Info 1',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L2',
            'capacite' => 25,
            'actif' => true,
        ]);

        $this->enseignantTitulaire = Teacher::create([
            'matricule' => 'ENS-P3-001',
            'nom' => 'Ben Salah',
            'prenom' => 'Mohamed',
            'email' => 'prof_titulaire_p3@test.tn',
            'charge_statutaire' => 200.0,
            'charge_hebdo_max' => 12.0,
            'charge_hebdo_min' => 4.0,
            'qualifications' => ['ALGO_P3'],
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->enseignantSecondaire = Teacher::create([
            'matricule' => 'ENS-P3-002',
            'nom' => 'Mansouri',
            'prenom' => 'Yassine',
            'email' => 'prof_secondaire_p3@test.tn',
            'charge_statutaire' => 180.0,
            'charge_hebdo_max' => 14.0,
            'charge_hebdo_min' => 4.0,
            'qualifications' => ['RES_P3'],
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->matiereAlgo = Matiere::create([
            'nom' => 'Algorithmique Avancée P3',
            'code' => 'ALGO_P3',
            'niveau' => 'L2',
            'semestre' => 1,
            'faculte_id' => $this->faculte->id,
        ]);

        $this->matiereReseaux = Matiere::create([
            'nom' => 'Réseaux et Protocoles P3',
            'code' => 'RES_P3',
            'niveau' => 'L2',
            'semestre' => 1,
            'faculte_id' => $this->faculte->id,
        ]);

        $this->schedule = Schedule::create([
            'annee_universitaire_id' => $this->annee->id,
            'semestre' => 1,
            'groupe_id' => $this->groupe->id,
            'nom' => 'Planning L2 Semestre 1 P3',
            'statut' => 'brouillon',
            'actif' => true,
        ]);
    }

    public function test_registre_definitions_par_defaut_exhaustives(): void
    {
        $defs = $this->registry->definitionsParDefaut();

        $this->assertCount(19, $defs);

        // 11 contraintes dures obligatoires
        $hardExpected = [
            TimetableConstraint::HARD_TEACHER_CONFLICT,
            TimetableConstraint::HARD_ROOM_CONFLICT,
            TimetableConstraint::HARD_GROUP_CONFLICT,
            TimetableConstraint::HARD_ROOM_CAPACITY,
            TimetableConstraint::HARD_TEACHER_UNAVAILABLE,
            TimetableConstraint::HARD_ROOM_UNAVAILABLE,
            TimetableConstraint::HARD_MISSING_EQUIPMENT,
            TimetableConstraint::HARD_TEACHER_QUALIFICATION,
            TimetableConstraint::HARD_ORPHAN_SLOT,
            TimetableConstraint::HARD_INSTITUTIONAL_PAUSE,
            TimetableConstraint::HARD_ROOM_OPENING_HOURS,
        ];

        foreach ($hardExpected as $code) {
            $this->assertArrayHasKey($code, $defs);
            $this->assertSame(TimetableConstraint::CATEGORIE_HARD, $defs[$code]['categorie']);
            $this->assertTrue($defs[$code]['actif']);
            $this->assertSame(100, $defs[$code]['poids']);
        }

        // 8 contraintes souples avec paramètres
        $softExpected = [
            TimetableConstraint::SOFT_STUDENT_IDLE_GAPS,
            TimetableConstraint::SOFT_TEACHER_IDLE_GAPS,
            TimetableConstraint::SOFT_MAX_CONSECUTIVE_HOURS,
            TimetableConstraint::SOFT_MAX_DAILY_HOURS,
            TimetableConstraint::SOFT_TEACHER_PREFERENCE,
            TimetableConstraint::SOFT_BUILDING_TRANSIT,
            TimetableConstraint::SOFT_ROOM_OVERSIZED,
            TimetableConstraint::SOFT_DAILY_EQUILIBRIUM,
        ];

        foreach ($softExpected as $code) {
            $this->assertArrayHasKey($code, $defs);
            $this->assertSame(TimetableConstraint::CATEGORIE_SOFT, $defs[$code]['categorie']);
            $this->assertTrue($defs[$code]['actif']);
            $this->assertGreaterThan(0, $defs[$code]['poids']);
            $this->assertIsArray($defs[$code]['parametres']);
        }
    }

    public function test_registre_initialisation_faculte_et_personnalisation(): void
    {
        $collection = $this->registry->initialiserPourFaculte($this->faculte->id);

        $this->assertCount(19, $collection);
        $this->assertDatabaseHas('timetable_constraints', [
            'faculte_id' => $this->faculte->id,
            'code' => TimetableConstraint::SOFT_STUDENT_IDLE_GAPS,
            'actif' => true,
        ]);

        // Désactivation d'une contrainte
        $this->registry->desactiverContrainte(TimetableConstraint::SOFT_STUDENT_IDLE_GAPS, $this->faculte->id);
        $this->assertFalse($this->registry->estActive(TimetableConstraint::SOFT_STUDENT_IDLE_GAPS, $this->faculte->id));

        // Ajustement du poids borné
        $this->registry->definirPoids(TimetableConstraint::SOFT_MAX_CONSECUTIVE_HOURS, 45, $this->faculte->id);
        $this->assertSame(45, $this->registry->getPoids(TimetableConstraint::SOFT_MAX_CONSECUTIVE_HOURS, $this->faculte->id));

        // Modification de paramètres
        $this->registry->mettreAJourParametres(
            TimetableConstraint::SOFT_MAX_CONSECUTIVE_HOURS,
            ['max_heures' => 3, 'penalite_par_heure_supp' => 15],
            $this->faculte->id
        );
        $params = $this->registry->getParametres(TimetableConstraint::SOFT_MAX_CONSECUTIVE_HOURS, $this->faculte->id);
        $this->assertSame(3, $params['max_heures']);
        $this->assertSame(15, $params['penalite_par_heure_supp']);
    }

    public function test_checker_planning_valide_score_100(): void
    {
        // Création de 2 créneaux parfaits le lundi avec pause réglementaire de 15 minutes (pas de trou > 30m, pas de conflit)
        ScheduleSlot::create([
            'schedule_id' => $this->schedule->id,
            'matiere_id' => $this->matiereAlgo->id,
            'teacher_id' => $this->enseignantTitulaire->id,
            'room_id' => $this->salleStandard->id,
            'day_of_week' => 'monday',
            'start_time' => '08:30:00',
            'end_time' => '10:00:00',
            'status' => 'scheduled',
            'semaine_parite' => 'toutes',
        ]);

        ScheduleSlot::create([
            'schedule_id' => $this->schedule->id,
            'matiere_id' => $this->matiereAlgo->id,
            'teacher_id' => $this->enseignantTitulaire->id,
            'room_id' => $this->salleStandard->id,
            'day_of_week' => 'monday',
            'start_time' => '10:15:00',
            'end_time' => '11:45:00',
            'status' => 'scheduled',
            'semaine_parite' => 'toutes',
        ]);

        $report = $this->checker->auditerPlanning($this->schedule);

        $this->assertTrue($report->estValide());
        $this->assertSame(100, $report->scoreQualite());
        $this->assertSame(0, $report->nombreViolationsDures());
        $this->assertSame(0, $report->nombrePenalitesSouples());
        $this->assertEquals(0, $report->totalPenalites());
        $this->assertSame(2, $report->metriques()['total_creneaux']);
        $this->assertSame(3.0, (float) $report->metriques()['total_heures']);
    }

    public function test_checker_detection_violations_dures(): void
    {
        // 1. Créneau orphelin (sans enseignant)
        $slot1 = ScheduleSlot::create([
            'schedule_id' => $this->schedule->id,
            'matiere_id' => $this->matiereAlgo->id,
            'teacher_id' => null,
            'room_id' => $this->salleStandard->id,
            'day_of_week' => 'tuesday',
            'start_time' => '08:30:00',
            'end_time' => '10:00:00',
            'status' => 'scheduled',
            'semaine_parite' => 'toutes',
        ]);

        // 2. Créneau avec capacité salle dépassée (effectif groupe 25 > capacité salle 10)
        $petiteSalle = Room::create([
            'code' => 'MINI_P3',
            'nom' => 'Mini Box',
            'capacite' => 10,
            'actif' => true,
            'faculte_id' => $this->faculte->id,
            'batiment_id' => $this->batimentA->id,
            'heure_ouverture' => '08:00:00',
            'heure_fermeture' => '18:00:00',
        ]);

        $slot2 = ScheduleSlot::create([
            'schedule_id' => $this->schedule->id,
            'matiere_id' => $this->matiereReseaux->id,
            'teacher_id' => $this->enseignantSecondaire->id,
            'room_id' => $petiteSalle->id,
            'day_of_week' => 'wednesday',
            'start_time' => '10:00:00',
            'end_time' => '11:30:00',
            'status' => 'scheduled',
            'semaine_parite' => 'toutes',
        ]);

        // 3. Enseignant non qualifié (matiereAlgo requiert ALGO_P3, mais enseignantSecondaire a RES_P3)
        $slot3 = ScheduleSlot::create([
            'schedule_id' => $this->schedule->id,
            'matiere_id' => $this->matiereAlgo->id,
            'teacher_id' => $this->enseignantSecondaire->id,
            'room_id' => $this->salleStandard->id,
            'day_of_week' => 'thursday',
            'start_time' => '14:00:00',
            'end_time' => '15:30:00',
            'status' => 'scheduled',
            'semaine_parite' => 'toutes',
        ]);

        $report = $this->checker->auditerPlanning($this->schedule);

        $this->assertFalse($report->estValide());
        $this->assertGreaterThanOrEqual(3, $report->nombreViolationsDures());

        $codesDurs = array_column($report->violationsDures(), 'code');
        $this->assertContains(TimetableConstraint::HARD_ORPHAN_SLOT, $codesDurs);
        $this->assertContains(TimetableConstraint::HARD_ROOM_CAPACITY, $codesDurs);
        $this->assertContains(TimetableConstraint::HARD_TEACHER_QUALIFICATION, $codesDurs);

        $this->assertNotEmpty($report->recommandations());
    }

    public function test_checker_detection_penalites_souples_trous_etudiants_et_enseignants(): void
    {
        // 2 cours espacés de 2h30 (150 minutes) le même jour -> trou d'attente pour le groupe et pour l'enseignant
        ScheduleSlot::create([
            'schedule_id' => $this->schedule->id,
            'matiere_id' => $this->matiereAlgo->id,
            'teacher_id' => $this->enseignantTitulaire->id,
            'room_id' => $this->salleStandard->id,
            'day_of_week' => 'tuesday',
            'start_time' => '08:30:00',
            'end_time' => '10:00:00',
            'status' => 'scheduled',
            'semaine_parite' => 'toutes',
        ]);

        ScheduleSlot::create([
            'schedule_id' => $this->schedule->id,
            'matiere_id' => $this->matiereAlgo->id,
            'teacher_id' => $this->enseignantTitulaire->id,
            'room_id' => $this->salleStandard->id,
            'day_of_week' => 'tuesday',
            'start_time' => '12:30:00',
            'end_time' => '14:00:00',
            'status' => 'scheduled',
            'semaine_parite' => 'toutes',
        ]);

        $report = $this->checker->auditerPlanning($this->schedule);

        $this->assertTrue($report->estValide()); // Aucune violation dure
        $this->assertLessThan(100, $report->scoreQualite());

        $codesSouples = array_column($report->penalitesSouples(), 'code');
        $this->assertContains(TimetableConstraint::SOFT_STUDENT_IDLE_GAPS, $codesSouples);
        $this->assertContains(TimetableConstraint::SOFT_TEACHER_IDLE_GAPS, $codesSouples);

        $metriques = $report->metriques();
        $this->assertSame(150, $metriques['total_minutes_trous_etudiants']);
        $this->assertSame(150, $metriques['total_minutes_trous_enseignants']);
    }

    public function test_checker_detection_penalite_transit_inter_batiments(): void
    {
        // Deux cours successifs dans deux bâtiments différents avec seulement 5 minutes de battement (< 15 min)
        ScheduleSlot::create([
            'schedule_id' => $this->schedule->id,
            'matiere_id' => $this->matiereAlgo->id,
            'teacher_id' => $this->enseignantTitulaire->id,
            'room_id' => $this->salleStandard->id, // Bâtiment A
            'day_of_week' => 'friday',
            'start_time' => '08:30:00',
            'end_time' => '10:00:00',
            'status' => 'scheduled',
            'semaine_parite' => 'toutes',
        ]);

        ScheduleSlot::create([
            'schedule_id' => $this->schedule->id,
            'matiere_id' => $this->matiereReseaux->id,
            'teacher_id' => $this->enseignantSecondaire->id,
            'room_id' => $this->salleBatB->id, // Bâtiment B
            'day_of_week' => 'friday',
            'start_time' => '10:05:00',
            'end_time' => '11:35:00',
            'status' => 'scheduled',
            'semaine_parite' => 'toutes',
        ]);

        $report = $this->checker->auditerPlanning($this->schedule);

        $codesSouples = array_column($report->penalitesSouples(), 'code');
        $this->assertContains(TimetableConstraint::SOFT_BUILDING_TRANSIT, $codesSouples);

        $transit = collect($report->penalitesSouples())->firstWhere('code', TimetableConstraint::SOFT_BUILDING_TRANSIT);
        $this->assertNotNull($transit);
        $this->assertSame(5, $transit['details']['battement']);
    }

    public function test_checker_detection_penalite_salle_surdimensionnee(): void
    {
        // Effectif groupe = 25, Grand Amphi = 200 places (ratio 8.0 > 2.5)
        ScheduleSlot::create([
            'schedule_id' => $this->schedule->id,
            'matiere_id' => $this->matiereAlgo->id,
            'teacher_id' => $this->enseignantTitulaire->id,
            'room_id' => $this->grandAmphi->id,
            'day_of_week' => 'wednesday',
            'start_time' => '08:30:00',
            'end_time' => '10:00:00',
            'status' => 'scheduled',
            'semaine_parite' => 'toutes',
        ]);

        $report = $this->checker->auditerPlanning($this->schedule);

        $codesSouples = array_column($report->penalitesSouples(), 'code');
        $this->assertContains(TimetableConstraint::SOFT_ROOM_OVERSIZED, $codesSouples);
    }

    public function test_checker_respect_preferences_enseignant(): void
    {
        // EnseignantTitulaire déclare un créneau souhaité le lundi matin de 08:00 à 12:00
        ScheduleAvailability::create([
            'teacher_id' => $this->enseignantTitulaire->id,
            'day_of_week' => 'monday',
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
            'type' => 'souhaitee',
            'annee_universitaire_id' => $this->annee->id,
        ]);

        // Séance programmée le jeudi après-midi (en dehors des créneaux souhaités)
        ScheduleSlot::create([
            'schedule_id' => $this->schedule->id,
            'matiere_id' => $this->matiereAlgo->id,
            'teacher_id' => $this->enseignantTitulaire->id,
            'room_id' => $this->salleStandard->id,
            'day_of_week' => 'thursday',
            'start_time' => '14:00:00',
            'end_time' => '15:30:00',
            'status' => 'scheduled',
            'semaine_parite' => 'toutes',
        ]);

        $report = $this->checker->auditerPlanning($this->schedule);

        $codesSouples = array_column($report->penalitesSouples(), 'code');
        $this->assertContains(TimetableConstraint::SOFT_TEACHER_PREFERENCE, $codesSouples);
    }

    public function test_checker_desactivation_contrainte_souple_supprime_la_penalite(): void
    {
        // 2 cours avec trou de 2h
        ScheduleSlot::create([
            'schedule_id' => $this->schedule->id,
            'matiere_id' => $this->matiereAlgo->id,
            'teacher_id' => $this->enseignantTitulaire->id,
            'room_id' => $this->salleStandard->id,
            'day_of_week' => 'monday',
            'start_time' => '08:00:00',
            'end_time' => '09:30:00',
            'status' => 'scheduled',
        ]);
        ScheduleSlot::create([
            'schedule_id' => $this->schedule->id,
            'matiere_id' => $this->matiereAlgo->id,
            'teacher_id' => $this->enseignantTitulaire->id,
            'room_id' => $this->salleStandard->id,
            'day_of_week' => 'monday',
            'start_time' => '11:30:00',
            'end_time' => '13:00:00',
            'status' => 'scheduled',
        ]);

        // Désactivation des contraintes de trous
        $this->registry->desactiverContrainte(TimetableConstraint::SOFT_STUDENT_IDLE_GAPS, $this->faculte->id);
        $this->registry->desactiverContrainte(TimetableConstraint::SOFT_TEACHER_IDLE_GAPS, $this->faculte->id);

        $report = $this->checker->auditerPlanning($this->schedule);

        $codes = array_column($report->penalitesSouples(), 'code');
        $this->assertNotContains(TimetableConstraint::SOFT_STUDENT_IDLE_GAPS, $codes);
        $this->assertNotContains(TimetableConstraint::SOFT_TEACHER_IDLE_GAPS, $codes);
    }

    public function test_integration_schedule_service_controle_publication_et_blocage(): void
    {
        // Planning avec un créneau orphelin (sans enseignant)
        $slot = ScheduleSlot::create([
            'schedule_id' => $this->schedule->id,
            'matiere_id' => $this->matiereAlgo->id,
            'teacher_id' => null,
            'room_id' => $this->salleStandard->id,
            'day_of_week' => 'monday',
            'start_time' => '08:30:00',
            'end_time' => '10:00:00',
            'status' => 'scheduled',
        ]);

        $controle = $this->scheduleService->controlePublication($this->schedule);

        $this->assertNotEmpty($controle['conflits']);
        $this->assertFalse($controle['est_valide']);
        $this->assertSame(1, $controle['sans_enseignant']);
        $this->assertInstanceOf(ScheduleAuditReport::class, $controle['audit']);
        $this->assertIsArray($controle['metriques']);

        // Tentative de publication bloquée
        $this->scheduleService->transiterPlanning($this->schedule, 'valide');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('bloquent la publication');
        $this->scheduleService->publierPlanning($this->schedule->fresh());
    }
}
