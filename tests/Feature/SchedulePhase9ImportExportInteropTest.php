<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Batiment;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use App\Modules\Schedule\Services\ScheduleExchangeService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulePhase9ImportExportInteropTest extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculte;

    protected Group $groupe;

    protected AnneeUniversitaire $annee;

    protected Batiment $batiment;

    protected Schedule $schedule;

    protected Matiere $matiereAlgo;

    protected Matiere $matiereWeb;

    protected Teacher $profAlgo;

    protected Teacher $profWeb;

    protected Room $salle101;

    protected User $gestionnaire;

    protected ScheduleExchangeService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences Test P9',
            'code' => 'FST_P9_'.uniqid(),
            'actif' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027-P9-'.uniqid(),
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->batiment = Batiment::create([
            'nom' => 'Bâtiment Informatique P9',
            'code' => 'BAT_P9_'.uniqid(),
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->groupe = Group::create([
            'nom' => 'Groupe L3 Info P9',
            'code' => 'L3_P9_'.uniqid(),
            'niveau' => 'L3',
            'capacite' => 30,
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->schedule = Schedule::create([
            'annee_universitaire_id' => $this->annee->id,
            'semestre' => 1,
            'groupe_id' => $this->groupe->id,
            'nom' => 'Emploi du temps L3 S1 P9',
            'statut' => 'brouillon',
            'actif' => true,
            'starts_at' => '2026-09-01',
            'ends_at' => '2027-01-15',
        ]);

        $codeAlgo = 'ALG_P9_'.substr(uniqid(), -6);
        $this->matiereAlgo = Matiere::create([
            'code' => $codeAlgo,
            'nom' => 'Algorithmique P9',
            'niveau' => 'L3',
            'semestre' => 1,
            'faculte_id' => $this->faculte->id,
        ]);

        $codeWeb = 'WEB_P9_'.substr(uniqid(), -6);
        $this->matiereWeb = Matiere::create([
            'code' => $codeWeb,
            'nom' => 'Développement Web P9',
            'niveau' => 'L3',
            'semestre' => 1,
            'faculte_id' => $this->faculte->id,
        ]);

        $this->profAlgo = Teacher::create([
            'matricule' => 'ENS-P9-01-'.substr(uniqid(), -6),
            'nom' => 'Ayari',
            'prenom' => 'Mohamed',
            'email' => 'mohamed.ayari@test.tn',
            'charge_statutaire' => 192.0,
            'qualifications' => [$codeAlgo],
            'actif' => true,
        ]);

        $this->profWeb = Teacher::create([
            'matricule' => 'ENS-P9-02-'.substr(uniqid(), -6),
            'nom' => 'Bouazizi',
            'prenom' => 'Sarra',
            'email' => 'sarra.bouazizi@test.tn',
            'charge_statutaire' => 192.0,
            'qualifications' => [$codeWeb],
            'actif' => true,
        ]);

        $this->salle101 = Room::create([
            'code' => 'S101_P9_'.uniqid(),
            'nom' => 'Salle 101 P9',
            'capacite' => 40,
            'batiment' => 'Bâtiment Informatique P9',
            'batiment_id' => $this->batiment->id,
            'faculte_id' => $this->faculte->id,
            'heure_ouverture' => '08:00:00',
            'heure_fermeture' => '19:00:00',
            'actif' => true,
        ]);

        $this->gestionnaire = User::factory()->create(['name' => 'Admin P9']);
        $this->service = app(ScheduleExchangeService::class);
    }

    public function test_export_ics_conforme_rfc5545_avec_recurrence_et_metadonnees(): void
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

        $ics = $this->service->exporterIcs($this->schedule);

        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('VERSION:2.0', $ics);
        $this->assertStringContainsString('BEGIN:VEVENT', $ics);
        $this->assertStringContainsString('SUMMARY:Algorithmique P9 [COURSE]', $ics);
        $this->assertStringContainsString('LOCATION:Salle Salle 101 P9 (Bâtiment Informatique P9)', $ics);
        $this->assertStringContainsString('RRULE:FREQ=WEEKLY;INTERVAL=1;UNTIL=', $ics);
        $this->assertStringContainsString('END:VCALENDAR', $ics);
    }

    public function test_export_ics_filtre_par_enseignant(): void
    {
        // Créneau Algo attribué au profAlgo
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

        // Créneau Web attribué au profWeb
        ScheduleSlot::create([
            'schedule_id' => $this->schedule->id,
            'matiere_id' => $this->matiereWeb->id,
            'teacher_id' => $this->profWeb->id,
            'room_id' => $this->salle101->id,
            'day_of_week' => 'tuesday',
            'start_time' => '10:15:00',
            'end_time' => '11:45:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $icsProfAlgo = $this->service->exporterIcs($this->schedule, [
            'teacher_id' => $this->profAlgo->id,
        ]);

        $this->assertStringContainsString('Algorithmique P9', $icsProfAlgo);
        $this->assertStringNotContainsString('Développement Web P9', $icsProfAlgo);
    }

    public function test_export_csv_genere_lignes_correctement_formatees(): void
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

        $csv = $this->service->exporterCsv($this->schedule);

        $this->assertStringContainsString('jour;heure_debut;heure_fin;matiere_code', $csv);
        $this->assertStringContainsString($this->matiereAlgo->code, $csv);
        $this->assertStringContainsString('08:30;10:00', $csv);
    }

    public function test_export_json_contient_structure_complete(): void
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

        $json = $this->service->exporterJson($this->schedule);

        $this->assertSame('SmartTimetables-1.0', $json['version_moteur']);
        $this->assertSame(1, $json['nb_creneaux']);
        $this->assertSame($this->matiereAlgo->code, $json['creneaux'][0]['matiere']['code']);
    }

    public function test_simulation_import_detecte_erreurs_sans_ecriture_en_base(): void
    {
        $donneesInvalides = [
            [
                'jour' => 'jour_inconnu',
                'heure_debut' => '10:00',
                'heure_fin' => '08:00', // Fin avant début !
                'matiere_code' => 'CODE_INEXISTANT',
            ],
        ];

        $resultat = $this->service->validerOuSimulerImport($this->schedule, $donneesInvalides);

        $this->assertFalse($resultat['succes']);
        $this->assertGreaterThanOrEqual(1, $resultat['nb_erreurs']);
        $this->assertDatabaseCount('schedule_slots', 0);
    }

    public function test_import_definitif_insere_creneaux_et_historise(): void
    {
        $donneesValides = [
            [
                'jour' => 'wednesday',
                'heure_debut' => '09:00',
                'heure_fin' => '11:00',
                'matiere_code' => $this->matiereAlgo->code,
                'enseignant_matricule' => $this->profAlgo->matricule,
                'salle_code' => $this->salle101->code,
                'type' => 'td',
            ],
        ];

        $bilan = $this->service->importerCreneaux(
            $this->schedule,
            $donneesValides,
            false,
            $this->gestionnaire->id,
            'Import initial semestre 1'
        );

        $this->assertSame(1, $bilan['nb_importes']);
        $this->assertDatabaseCount('schedule_slots', 1);

        $slot = ScheduleSlot::first();
        $this->assertSame('wednesday', $slot->day_of_week);
        $this->assertSame('09:00:00', $slot->start_time);
        $this->assertSame('td', $slot->type);

        $this->assertDatabaseHas('schedule_changes', [
            'schedule_slot_id' => $slot->id,
            'change_type' => 'created',
            'changed_by' => $this->gestionnaire->id,
        ]);
    }

    public function test_import_interdit_sur_planning_verrouille(): void
    {
        $this->schedule->update(['statut' => 'verrouille']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('verrouillé ou archivé');

        $this->service->importerCreneaux($this->schedule, []);
    }
}
