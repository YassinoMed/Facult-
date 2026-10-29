<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Batiment;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use App\Modules\Schedule\Models\TimeSlot;
use App\Modules\Schedule\Services\ScheduleBenchmarkService;
use App\Modules\Schedule\Services\SeanceDemande;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulePhase11BenchmarksOptimisationTest extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculte;

    protected Group $groupe;

    protected AnneeUniversitaire $annee;

    protected Batiment $batiment;

    protected Schedule $schedule;

    protected Matiere $matiereAlgo;

    protected Teacher $profAlgo;

    protected Room $salle101;

    protected ScheduleBenchmarkService $benchmarkService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté Polytechnique P11',
            'code' => 'POLY_P11_'.uniqid(),
            'actif' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027-P11-'.uniqid(),
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->batiment = Batiment::create([
            'nom' => 'Bâtiment Principal P11',
            'code' => 'BAT_P11_'.uniqid(),
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->groupe = Group::create([
            'nom' => 'Groupe L3 P11',
            'code' => 'L3_P11_'.uniqid(),
            'niveau' => 'L3',
            'capacite' => 30,
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->schedule = Schedule::create([
            'annee_universitaire_id' => $this->annee->id,
            'semestre' => 1,
            'groupe_id' => $this->groupe->id,
            'nom' => 'Planning L3 S1 P11',
            'statut' => 'brouillon',
            'actif' => true,
            'starts_at' => '2026-09-01',
            'ends_at' => '2027-01-15',
        ]);

        $codeAlgo = 'ALG_P11_'.substr(uniqid(), -6);
        $this->matiereAlgo = Matiere::create([
            'code' => $codeAlgo,
            'nom' => 'Algorithmique P11',
            'niveau' => 'L3',
            'semestre' => 1,
            'faculte_id' => $this->faculte->id,
        ]);

        $this->profAlgo = Teacher::create([
            'matricule' => 'ENS-P11-'.substr(uniqid(), -6),
            'nom' => 'Dridi',
            'prenom' => 'Walid',
            'email' => 'walid.dridi@test.tn',
            'charge_statutaire' => 192.0,
            'qualifications' => [$codeAlgo],
            'actif' => true,
        ]);

        $this->salle101 = Room::create([
            'code' => 'S101_P11_'.uniqid(),
            'nom' => 'Salle 101 P11',
            'capacite' => 40,
            'batiment' => 'Bâtiment Principal P11',
            'batiment_id' => $this->batiment->id,
            'faculte_id' => $this->faculte->id,
            'heure_ouverture' => '08:00:00',
            'heure_fermeture' => '19:00:00',
            'actif' => true,
        ]);

        // Créneaux horaires
        TimeSlot::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'SLOT_P11_1',
            'nom' => 'Matin 1',
            'day_of_week' => 'monday',
            'start_time' => '08:30:00',
            'end_time' => '10:00:00',
            'type' => 'course',
            'actif' => true,
        ]);
        TimeSlot::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'SLOT_P11_2',
            'nom' => 'Matin 2',
            'day_of_week' => 'monday',
            'start_time' => '10:15:00',
            'end_time' => '11:45:00',
            'type' => 'course',
            'actif' => true,
        ]);

        $this->benchmarkService = app(ScheduleBenchmarkService::class);
    }

    public function test_mesure_performance_generation_fournit_indicateurs_precis(): void
    {
        $demandes = [
            new SeanceDemande(
                id: 'S1',
                matiereId: $this->matiereAlgo->id,
                teacherId: $this->profAlgo->id,
                type: 'course',
                dureeMinutes: 90
            ),
        ];

        $mesure = $this->benchmarkService->mesurerPerformanceGeneration($this->schedule, $demandes);

        $this->assertArrayHasKey('temps_resolution_ms', $mesure);
        $this->assertArrayHasKey('nb_creneaux_generes', $mesure);
        $this->assertArrayHasKey('nb_requetes_sql', $mesure);
        $this->assertArrayHasKey('memoire_pic_mo', $mesure);

        $this->assertSame(1, $mesure['nb_creneaux_generes']);
        $this->assertGreaterThan(0.0, $mesure['temps_resolution_ms']);
    }

    public function test_mesure_performance_audit_independant(): void
    {
        ScheduleSlot::create([
            'schedule_id' => $this->schedule->id,
            'matiere_id' => $this->matiereAlgo->id,
            'teacher_id' => $this->profAlgo->id,
            'room_id' => $this->salle101->id,
            'day_of_week' => 'monday',
            'start_time' => '08:30:00',
            'end_time' => '10:00:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $mesure = $this->benchmarkService->mesurerPerformanceAudit($this->schedule);

        $this->assertArrayHasKey('temps_audit_ms', $mesure);
        $this->assertArrayHasKey('score_qualite', $mesure);
        $this->assertArrayHasKey('nb_requetes_sql', $mesure);
        $this->assertSame(0, $mesure['nb_conflits_durs']);
    }

    public function test_mesure_performance_export_ics_csv_json(): void
    {
        ScheduleSlot::create([
            'schedule_id' => $this->schedule->id,
            'matiere_id' => $this->matiereAlgo->id,
            'teacher_id' => $this->profAlgo->id,
            'room_id' => $this->salle101->id,
            'day_of_week' => 'monday',
            'start_time' => '08:30:00',
            'end_time' => '10:00:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $mesure = $this->benchmarkService->mesurerPerformanceExport($this->schedule);

        $this->assertGreaterThan(0.0, $mesure['temps_export_ics_ms']);
        $this->assertGreaterThan(0.0, $mesure['temps_export_csv_ms']);
        $this->assertGreaterThan(0.0, $mesure['temps_export_json_ms']);
        $this->assertGreaterThan(100, $mesure['taille_ics_octets']);
    }

    public function test_benchmark_complet_calcule_percentiles_p50_p95_et_evalue_n_plus_un(): void
    {
        $demandes = [
            new SeanceDemande(
                id: 'S1',
                matiereId: $this->matiereAlgo->id,
                teacherId: $this->profAlgo->id,
                type: 'course',
                dureeMinutes: 90
            ),
        ];

        $rapport = $this->benchmarkService->executerBenchmarkComplet($this->schedule, $demandes, 2);

        $this->assertSame(2, $rapport['iterations']);
        $this->assertArrayHasKey('generation', $rapport);
        $this->assertArrayHasKey('audit', $rapport);
        $this->assertArrayHasKey('export_ics', $rapport);
        $this->assertSame('optimal_sans_n_plus_un', $rapport['evaluation_n_plus_un']);

        $this->assertGreaterThanOrEqual(0.0, $rapport['generation']['p50_ms']);
        $this->assertGreaterThanOrEqual(0.0, $rapport['audit']['p95_ms']);
    }

    public function test_absence_de_n_plus_un_sur_export_planning_volumineux(): void
    {
        // Création de 10 créneaux successifs
        for ($i = 0; $i < 10; $i++) {
            ScheduleSlot::create([
                'schedule_id' => $this->schedule->id,
                'matiere_id' => $this->matiereAlgo->id,
                'teacher_id' => $this->profAlgo->id,
                'room_id' => $this->salle101->id,
                'day_of_week' => 'monday',
                'start_time' => sprintf('%02d:00:00', 8 + $i),
                'end_time' => sprintf('%02d:45:00', 8 + $i),
                'type' => 'course',
                'status' => 'scheduled',
            ]);
        }

        $mesure = $this->benchmarkService->mesurerPerformanceExport($this->schedule);

        // Pour les 3 exports consécutifs (ICS + CSV + JSON), le total de requêtes reste extrêmement bas (<= 20)
        $this->assertLessThanOrEqual(20, $mesure['nb_requetes_sql']);
    }
}
