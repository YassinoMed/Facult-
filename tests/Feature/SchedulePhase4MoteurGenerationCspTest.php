<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Batiment;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Modules\Enseignement\Models\SousGroupe;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleAvailability;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use App\Modules\Schedule\Models\TimeSlot;
use App\Modules\Schedule\Models\TimetableGeneration;
use App\Modules\Schedule\Services\ScheduleConstraintChecker;
use App\Modules\Schedule\Services\ScheduleGeneratorService;
use App\Modules\Schedule\Services\ScheduleService;
use App\Modules\Schedule\Services\SeanceDemande;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests exhaustifs de PHASE 4 : Moteur de génération automatique CSP.
 */
class SchedulePhase4MoteurGenerationCspTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Batiment $batiment;

    private Room $amphi;

    private Room $salleTd;

    private Room $laboTp;

    private Group $groupe;

    private SousGroupe $sousGroupe1;

    private SousGroupe $sousGroupe2;

    private Teacher $profAlgo;

    private Teacher $profWeb;

    private Matiere $matiereAlgo;

    private Matiere $matiereWeb;

    private Schedule $schedule;

    private ScheduleGeneratorService $generator;

    private ScheduleConstraintChecker $checker;

    private ScheduleService $scheduleService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generator = app(ScheduleGeneratorService::class);
        $this->checker = app(ScheduleConstraintChecker::class);
        $this->scheduleService = app(ScheduleService::class);

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences P4',
            'code' => 'FST_P4',
            'actif' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027-P4',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->batiment = Batiment::create([
            'nom' => 'Bâtiment Sciences P4',
            'code' => 'BAT_SCI_P4',
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->amphi = Room::create([
            'code' => 'AMPHI_P4',
            'nom' => 'Amphi Pasteur P4',
            'capacite' => 150,
            'batiment_id' => $this->batiment->id,
            'faculte_id' => $this->faculte->id,
            'type_salle' => 'amphi',
            'heure_ouverture' => '08:00:00',
            'heure_fermeture' => '19:00:00',
            'equipements_fixes' => ['videoprojecteur', 'sonorisation'],
            'actif' => true,
        ]);

        $this->salleTd = Room::create([
            'code' => 'TD101_P4',
            'nom' => 'Salle TD 101 P4',
            'capacite' => 40,
            'batiment_id' => $this->batiment->id,
            'faculte_id' => $this->faculte->id,
            'type_salle' => 'td',
            'heure_ouverture' => '08:00:00',
            'heure_fermeture' => '19:00:00',
            'equipements_fixes' => ['videoprojecteur'],
            'actif' => true,
        ]);

        $this->laboTp = Room::create([
            'code' => 'LAB201_P4',
            'nom' => 'Laboratoire TP Info P4',
            'capacite' => 25,
            'batiment_id' => $this->batiment->id,
            'faculte_id' => $this->faculte->id,
            'type_salle' => 'tp',
            'heure_ouverture' => '08:00:00',
            'heure_fermeture' => '19:00:00',
            'equipements_fixes' => ['postes_informatiques', 'videoprojecteur'],
            'actif' => true,
        ]);

        $this->groupe = Group::create([
            'code' => 'L3_GL_P4',
            'nom' => 'L3 Génie Logiciel P4',
            'capacite' => 30,
            'niveau' => 'L3',
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->sousGroupe1 = SousGroupe::create([
            'groupe_id' => $this->groupe->id,
            'code' => 'L3_GL_TP1_P4',
            'nom' => 'TP 1',
            'capacite' => 15,
            'type' => 'tp',
            'actif' => true,
        ]);

        $this->sousGroupe2 = SousGroupe::create([
            'groupe_id' => $this->groupe->id,
            'code' => 'L3_GL_TP2_P4',
            'nom' => 'TP 2',
            'capacite' => 15,
            'type' => 'tp',
            'actif' => true,
        ]);

        $this->profAlgo = Teacher::create([
            'matricule' => 'ENS-P4-001',
            'nom' => 'Bouazizi',
            'prenom' => 'Karim',
            'email' => 'karim.bouazizi@test.tn',
            'charge_statutaire' => 192.0,
            'charge_hebdo_max' => 14.0,
            'charge_hebdo_min' => 4.0,
            'qualifications' => ['ALGO_P4'],
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->profWeb = Teacher::create([
            'matricule' => 'ENS-P4-002',
            'nom' => 'Kefi',
            'prenom' => 'Salma',
            'email' => 'salma.kefi@test.tn',
            'charge_statutaire' => 192.0,
            'charge_hebdo_max' => 14.0,
            'charge_hebdo_min' => 4.0,
            'qualifications' => ['WEB_P4'],
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->matiereAlgo = Matiere::create([
            'nom' => 'Algorithmique & Structures P4',
            'code' => 'ALGO_P4',
            'niveau' => 'L3',
            'semestre' => 1,
            'faculte_id' => $this->faculte->id,
        ]);

        $this->matiereWeb = Matiere::create([
            'nom' => 'Développement Web Moderne P4',
            'code' => 'WEB_P4',
            'niveau' => 'L3',
            'semestre' => 1,
            'faculte_id' => $this->faculte->id,
        ]);

        $this->schedule = Schedule::create([
            'annee_universitaire_id' => $this->annee->id,
            'semestre' => 1,
            'groupe_id' => $this->groupe->id,
            'nom' => 'Emploi du temps L3 GL P4',
            'statut' => 'brouillon',
            'actif' => true,
        ]);

        // Création de créneaux standards dans TimeSlot
        TimeSlot::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'SLOT_LUN_1',
            'nom' => 'Lundi 08:30-10:00',
            'day_of_week' => 'monday',
            'start_time' => '08:30:00',
            'end_time' => '10:00:00',
            'type' => 'course',
            'actif' => true,
        ]);
        TimeSlot::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'SLOT_LUN_2',
            'nom' => 'Lundi 10:15-11:45',
            'day_of_week' => 'monday',
            'start_time' => '10:15:00',
            'end_time' => '11:45:00',
            'type' => 'course',
            'actif' => true,
        ]);
        TimeSlot::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'SLOT_MAR_1',
            'nom' => 'Mardi 08:30-10:00',
            'day_of_week' => 'tuesday',
            'start_time' => '08:30:00',
            'end_time' => '10:00:00',
            'type' => 'course',
            'actif' => true,
        ]);
        TimeSlot::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'SLOT_MAR_2',
            'nom' => 'Mardi 10:15-11:45',
            'day_of_week' => 'tuesday',
            'start_time' => '10:15:00',
            'end_time' => '11:45:00',
            'type' => 'course',
            'actif' => true,
        ]);
    }

    public function test_generation_csp_solution_complete_sans_conflit(): void
    {
        // 4 séances à placer : 2 CM Algo + 2 TD Web
        $demandes = [
            new SeanceDemande(
                id: 'cm_algo_1',
                matiereId: $this->matiereAlgo->id,
                type: 'course',
                titre: 'CM Algo 1',
                dureeMinutes: 90,
                teacherId: $this->profAlgo->id,
                equipementsRequis: ['videoprojecteur']
            ),
            new SeanceDemande(
                id: 'cm_algo_2',
                matiereId: $this->matiereAlgo->id,
                type: 'course',
                titre: 'CM Algo 2',
                dureeMinutes: 90,
                teacherId: $this->profAlgo->id,
                equipementsRequis: ['videoprojecteur']
            ),
            new SeanceDemande(
                id: 'td_web_1',
                matiereId: $this->matiereWeb->id,
                type: 'td',
                titre: 'TD Web 1',
                dureeMinutes: 90,
                teacherId: $this->profWeb->id
            ),
            new SeanceDemande(
                id: 'td_web_2',
                matiereId: $this->matiereWeb->id,
                type: 'td',
                titre: 'TD Web 2',
                dureeMinutes: 90,
                teacherId: $this->profWeb->id
            ),
        ];

        $generation = $this->generator->generer($this->schedule, [
            'seances_demandes' => $demandes,
            'ecraser_brouillon' => true,
        ]);

        $this->assertInstanceOf(TimetableGeneration::class, $generation);
        $this->assertSame(TimetableGeneration::STATUT_SUCCES, $generation->statut);
        $this->assertTrue($generation->estSucces());
        $this->assertSame(4, $generation->nombre_seances_a_placer);
        $this->assertSame(4, $generation->nombre_seances_placees);
        $this->assertSame(100.0, $generation->tauxPlacement());
        $this->assertGreaterThan(0, $generation->duree_ms);
        $this->assertGreaterThanOrEqual(70, $generation->score_qualite);

        // Vérification de la persistance en base
        $this->assertDatabaseCount('schedule_slots', 4);
        $this->assertSame('brouillon', $this->schedule->fresh()->statut); // GARANTIE : Reste brouillon

        // Audit indépendant via ScheduleConstraintChecker
        $audit = $this->checker->auditerPlanning($this->schedule->fresh());
        $this->assertTrue($audit->estValide());
        $this->assertSame(0, $audit->nombreViolationsDures());
    }

    public function test_simulation_dry_run_ne_persiste_aucun_creneau(): void
    {
        $demandes = [
            new SeanceDemande(
                id: 'sim_cours_1',
                matiereId: $this->matiereAlgo->id,
                type: 'course',
                titre: 'Simulation Cours Algo',
                teacherId: $this->profAlgo->id
            ),
        ];

        $generation = $this->generator->simuler($this->schedule, [
            'seances_demandes' => $demandes,
        ]);

        $this->assertSame(TimetableGeneration::STATUT_SUCCES, $generation->statut);
        $this->assertTrue($generation->parametres['dry_run']);
        $this->assertSame(1, $generation->nombre_seances_placees);

        // Zéro écriture dans schedule_slots
        $this->assertDatabaseCount('schedule_slots', 0);
    }

    public function test_generation_respect_contraintes_dures_equipements_et_capacite(): void
    {
        // Séance TP exigeant des postes informatiques
        $demandeTp = new SeanceDemande(
            id: 'tp_web_machine',
            matiereId: $this->matiereWeb->id,
            type: 'tp',
            titre: 'TP Machine Web',
            teacherId: $this->profWeb->id,
            sousGroupeId: $this->sousGroupe1->id,
            equipementsRequis: ['postes_informatiques']
        );

        $generation = $this->generator->generer($this->schedule, [
            'seances_demandes' => [$demandeTp],
            'ecraser_brouillon' => true,
        ]);

        $this->assertTrue($generation->estSucces());

        $slot = ScheduleSlot::first();
        $this->assertNotNull($slot);
        // Doit obligatoirement avoir été placé dans laboTp (seule salle avec 'postes_informatiques')
        $this->assertSame($this->laboTp->id, $slot->room_id);
    }

    public function test_generation_respect_indisponibilite_enseignant(): void
    {
        // ProfAlgo indisponible tout le lundi matin
        ScheduleAvailability::create([
            'teacher_id' => $this->profAlgo->id,
            'day_of_week' => 'monday',
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
            'type' => 'indisponible',
            'annee_universitaire_id' => $this->annee->id,
            'reason' => 'Conseil scientifique externe',
        ]);

        $demandes = [
            new SeanceDemande(
                id: 'cm_algo_lundi_refuse',
                matiereId: $this->matiereAlgo->id,
                type: 'course',
                titre: 'CM Algo',
                teacherId: $this->profAlgo->id
            ),
        ];

        $generation = $this->generator->generer($this->schedule, [
            'seances_demandes' => $demandes,
            'ecraser_brouillon' => true,
        ]);

        $this->assertTrue($generation->estSucces());
        $slot = ScheduleSlot::first();
        $this->assertNotNull($slot);

        // Le créneau doit être planifié le mardi, JAMAIS le lundi !
        $this->assertSame('tuesday', $slot->day_of_week);
    }

    public function test_generation_cas_saturation_statut_partiel_sans_crash(): void
    {
        // Création d'un calendrier très restreint : 1 seul créneau disponible (Lundi 08:30)
        // et demande de 5 séances concurrentes pour le même groupe
        $demandes = [];
        for ($i = 1; $i <= 5; $i++) {
            $demandes[] = new SeanceDemande(
                id: "cours_sature_{$i}",
                matiereId: $this->matiereAlgo->id,
                type: 'course',
                titre: "Séance Surcharge #{$i}",
                teacherId: $this->profAlgo->id
            );
        }

        // On restreint à 1 seul créneau possible dans les options
        $generation = $this->generator->generer($this->schedule, [
            'seances_demandes' => $demandes,
            'jours_autorises' => ['monday'], // Lundi n'a que 2 créneaux
            'ecraser_brouillon' => true,
            'iteration_max' => 500,
        ]);

        // Ne peut pas placer les 5 séances (au maximum 2 créneaux le lundi pour un même groupe)
        $this->assertTrue($generation->estPartiel() || $generation->estSucces());
        $this->assertLessThanOrEqual(2, $generation->nombre_seances_placees);
        $this->assertLessThan(5, $generation->nombre_seances_placees);
    }

    public function test_generation_ecraser_brouillon_vs_incremental(): void
    {
        // 1 créneau initial déjà placé
        $slotInitial = ScheduleSlot::create([
            'schedule_id' => $this->schedule->id,
            'matiere_id' => $this->matiereAlgo->id,
            'teacher_id' => $this->profAlgo->id,
            'room_id' => $this->amphi->id,
            'day_of_week' => 'monday',
            'start_time' => '08:30:00',
            'end_time' => '10:00:00',
            'status' => 'scheduled',
        ]);

        $nouvelleDemande = [
            new SeanceDemande(
                id: 'seance_ajoutee',
                matiereId: $this->matiereWeb->id,
                type: 'td',
                titre: 'TD Web Ajouté',
                teacherId: $this->profWeb->id
            ),
        ];

        // Mode incrémental (ecraser_brouillon => false)
        $generation = $this->generator->generer($this->schedule, [
            'seances_demandes' => $nouvelleDemande,
            'ecraser_brouillon' => false,
        ]);

        $this->assertTrue($generation->estSucces());
        // Doit comporter 2 créneaux au total : l'ancien + le nouveau
        $this->assertDatabaseCount('schedule_slots', 2);
        $this->assertDatabaseHas('schedule_slots', ['id' => $slotInitial->id]);
    }

    public function test_integration_schedule_service_generer_et_publier(): void
    {
        $demandes = [
            new SeanceDemande(
                id: 'cm_integ_1',
                matiereId: $this->matiereAlgo->id,
                type: 'course',
                titre: 'CM Algo Intégration',
                teacherId: $this->profAlgo->id,
                roomId: $this->amphi->id
            ),
        ];

        // Appel via ScheduleService
        $generation = $this->scheduleService->genererAutomatiquement($this->schedule, [
            'seances_demandes' => $demandes,
            'ecraser_brouillon' => true,
        ]);

        $this->assertTrue($generation->estSucces());

        // Contrôle de publication
        $controle = $this->scheduleService->controlePublication($this->schedule);
        $this->assertTrue($controle['est_valide']);
        $this->assertEmpty($controle['conflits']);

        // Publication finale
        $this->scheduleService->transiterPlanning($this->schedule, 'valide');
        $this->scheduleService->publierPlanning($this->schedule);

        $this->assertSame('publie', $this->schedule->fresh()->statut);
    }
}
