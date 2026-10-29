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
use App\Modules\Schedule\Models\TimeSlot;
use App\Modules\Schedule\Models\TimetableScenario;
use App\Modules\Schedule\Services\ScheduleOptimizerService;
use App\Modules\Schedule\Services\SeanceDemande;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests exhaustifs de PHASE 5 : Scénarios comparatifs, optimisation et diagnostic d'infaisabilité.
 */
class SchedulePhase5ScenariosOptimisationDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Batiment $batiment;

    private Room $salle1;

    private Room $salle2;

    private Group $groupe;

    private Teacher $profAlgo;

    private Matiere $matiereAlgo;

    private Schedule $schedule;

    private ScheduleOptimizerService $optimizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->optimizer = app(ScheduleOptimizerService::class);

        $this->faculte = Faculte::create([
            'nom' => 'Faculté Polytechnique P5',
            'code' => 'POLY_P5',
            'actif' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027-P5',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->batiment = Batiment::create([
            'nom' => 'Bâtiment Principal P5',
            'code' => 'BAT_P5',
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->salle1 = Room::create([
            'code' => 'S101_P5',
            'nom' => 'Salle 101 P5',
            'capacite' => 40,
            'batiment_id' => $this->batiment->id,
            'faculte_id' => $this->faculte->id,
            'heure_ouverture' => '08:00:00',
            'heure_fermeture' => '19:00:00',
            'actif' => true,
        ]);

        $this->salle2 = Room::create([
            'code' => 'S102_P5',
            'nom' => 'Salle 102 P5',
            'capacite' => 40,
            'batiment_id' => $this->batiment->id,
            'faculte_id' => $this->faculte->id,
            'heure_ouverture' => '08:00:00',
            'heure_fermeture' => '19:00:00',
            'actif' => true,
        ]);

        $this->groupe = Group::create([
            'code' => 'G_P5',
            'nom' => 'Groupe P5',
            'niveau' => 'L3',
            'capacite' => 25,
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->profAlgo = Teacher::create([
            'matricule' => 'ENS-P5-001',
            'nom' => 'Mejri',
            'prenom' => 'Anis',
            'email' => 'anis.mejri@test.tn',
            'charge_statutaire' => 192.0,
            'qualifications' => ['ALGO_P5'],
            'actif' => true,
        ]);

        $this->matiereAlgo = Matiere::create([
            'nom' => 'Algorithmique P5',
            'code' => 'ALGO_P5',
            'niveau' => 'L3',
            'semestre' => 1,
            'faculte_id' => $this->faculte->id,
        ]);

        $this->schedule = Schedule::create([
            'annee_universitaire_id' => $this->annee->id,
            'semestre' => 1,
            'groupe_id' => $this->groupe->id,
            'nom' => 'Planning P5',
            'statut' => 'brouillon',
            'actif' => true,
        ]);

        TimeSlot::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'SLOT_P5_1',
            'nom' => 'Créneau 1',
            'day_of_week' => 'monday',
            'start_time' => '08:30:00',
            'end_time' => '10:00:00',
            'type' => 'course',
            'actif' => true,
        ]);
        TimeSlot::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'SLOT_P5_2',
            'nom' => 'Créneau 2',
            'day_of_week' => 'monday',
            'start_time' => '10:15:00',
            'end_time' => '11:45:00',
            'type' => 'course',
            'actif' => true,
        ]);
    }

    public function test_optimisation_amelioration_score_qualite_scenario(): void
    {
        // 2 séances avec un grand trou de 2h
        ScheduleSlot::create([
            'schedule_id' => $this->schedule->id,
            'matiere_id' => $this->matiereAlgo->id,
            'teacher_id' => $this->profAlgo->id,
            'room_id' => $this->salle1->id,
            'day_of_week' => 'monday',
            'start_time' => '08:30:00',
            'end_time' => '10:00:00',
            'status' => 'scheduled',
        ]);
        ScheduleSlot::create([
            'schedule_id' => $this->schedule->id,
            'matiere_id' => $this->matiereAlgo->id,
            'teacher_id' => $this->profAlgo->id,
            'room_id' => $this->salle1->id,
            'day_of_week' => 'monday',
            'start_time' => '12:00:00',
            'end_time' => '13:30:00',
            'status' => 'scheduled',
        ]);

        $scenario = $this->optimizer->optimiser($this->schedule, [
            'nom' => 'Scénario Compact',
            'iterations' => 10,
        ]);

        $this->assertInstanceOf(TimetableScenario::class, $scenario);
        $this->assertSame(TimetableScenario::STATUT_EVALUE, $scenario->statut);
        $this->assertGreaterThanOrEqual(0, $scenario->score_qualite);
        $this->assertSame(0, $scenario->nb_conflits_durs);
        $this->assertCount(2, $scenario->creneaux_json);
    }

    public function test_application_scenario_sur_planning_en_brouillon(): void
    {
        $creneauxScenario = [
            [
                'matiere_id' => $this->matiereAlgo->id,
                'teacher_id' => $this->profAlgo->id,
                'room_id' => $this->salle1->id,
                'day_of_week' => 'monday',
                'start_time' => '08:30:00',
                'end_time' => '10:00:00',
                'type' => 'course',
            ],
            [
                'matiere_id' => $this->matiereAlgo->id,
                'teacher_id' => $this->profAlgo->id,
                'room_id' => $this->salle1->id,
                'day_of_week' => 'monday',
                'start_time' => '10:15:00',
                'end_time' => '11:45:00',
                'type' => 'course',
            ],
        ];

        $scenario = TimetableScenario::create([
            'schedule_id' => $this->schedule->id,
            'code' => 'SCEN_OPT_1',
            'nom' => 'Variante Matinale',
            'statut' => TimetableScenario::STATUT_EVALUE,
            'score_qualite' => 100,
            'nb_conflits_durs' => 0,
            'creneaux_json' => $creneauxScenario,
        ]);

        $planningApres = $scenario->appliquerSurPlanning();

        $this->assertSame('brouillon', $planningApres->statut);
        $this->assertSame(TimetableScenario::STATUT_RETENU, $scenario->fresh()->statut);
        $this->assertDatabaseCount('schedule_slots', 2);
    }

    public function test_application_scenario_interdite_sur_planning_verrouille(): void
    {
        $this->schedule->update(['statut' => 'publie']);

        $scenario = TimetableScenario::create([
            'schedule_id' => $this->schedule->id,
            'code' => 'SCEN_ERR',
            'nom' => 'Test Rejet',
            'statut' => TimetableScenario::STATUT_EVALUE,
            'creneaux_json' => [],
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("Impossible d'appliquer un scénario");
        $scenario->appliquerSurPlanning();
    }

    public function test_diagnostic_infaisabilite_detecte_goulots_et_recommandations(): void
    {
        // Professeur indisponible avec 3 déclarations bloquantes
        for ($i = 1; $i <= 3; $i++) {
            ScheduleAvailability::create([
                'teacher_id' => $this->profAlgo->id,
                'day_of_week' => 'monday',
                'start_time' => "0{$i}:00:00",
                'end_time' => "0{$i}:30:00",
                'type' => 'indisponible',
                'reason' => 'Indisponibilité administrative',
            ]);
        }

        // Demande d'un cours avec équipement introuvable
        $demandeBloquee = new SeanceDemande(
            id: 'seance_impossible',
            matiereId: $this->matiereAlgo->id,
            type: 'tp',
            titre: 'TP Réalité Virtuelle',
            teacherId: $this->profAlgo->id,
            equipementsRequis: ['casques_vr_introuvables']
        );

        $diagnostic = $this->optimizer->diagnostiquerInfaisabilite($this->schedule, [$demandeBloquee]);

        $this->assertArrayHasKey('seance_impossible', $diagnostic);
        $diag = $diagnostic['seance_impossible'];
        $this->assertNotEmpty($diag['bloquants']);
        $this->assertNotEmpty($diag['recommandations']);

        $texteBloquants = implode(' ', $diag['bloquants']);
        $this->assertStringContainsString('équipements requis', $texteBloquants);
        $this->assertStringContainsString('indisponibilité', $texteBloquants);
    }

    public function test_comparaison_multiscenarios_matrice_et_recommandation(): void
    {
        $s1 = TimetableScenario::create([
            'schedule_id' => $this->schedule->id,
            'code' => 'SC1',
            'nom' => 'Scénario Haute Qualité',
            'statut' => TimetableScenario::STATUT_EVALUE,
            'score_qualite' => 95,
            'nb_conflits_durs' => 0,
            'creneaux_json' => [['slot' => 1]],
            'metriques_json' => ['total_minutes_trous_etudiants' => 0],
        ]);

        $s2 = TimetableScenario::create([
            'schedule_id' => $this->schedule->id,
            'code' => 'SC2',
            'nom' => 'Scénario Moyen',
            'statut' => TimetableScenario::STATUT_EVALUE,
            'score_qualite' => 70,
            'nb_conflits_durs' => 0,
            'creneaux_json' => [['slot' => 1]],
            'metriques_json' => ['total_minutes_trous_etudiants' => 120],
        ]);

        $comparaison = $this->optimizer->comparerScenarios([$s1, $s2]);

        $this->assertCount(2, $comparaison['scenarios']);
        $this->assertSame($s1->id, $comparaison['recommandation']['scenario_id']);
        $this->assertSame(95, $comparaison['recommandation']['score_qualite']);
    }
}
