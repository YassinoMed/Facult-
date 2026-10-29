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
use App\Modules\Schedule\Models\ScheduleAvailability;
use App\Modules\Schedule\Models\ScheduleChange;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use App\Modules\Schedule\Services\ScheduleRepairService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulePhase8ReparationRemplacementsTest extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculte;

    protected Group $groupe;

    protected AnneeUniversitaire $annee;

    protected Batiment $batimentA;

    protected Batiment $batimentB;

    protected Schedule $schedule;

    protected Matiere $matiereAlgo;

    protected Teacher $profTitulaire;

    protected Teacher $profRemplacantLibre;

    protected Teacher $profNonQualifie;

    protected Teacher $profIndisponible;

    protected Room $salleInitiale;

    protected Room $salleAlternativeMemeBat;

    protected Room $salleAutreBat;

    protected User $gestionnaire;

    protected ScheduleRepairService $repairService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté Polytechnique P8',
            'code' => 'POLY_P8_'.uniqid(),
            'actif' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027-P8-'.uniqid(),
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->batimentA = Batiment::create([
            'nom' => 'Bâtiment Informatique P8',
            'code' => 'BATA_P8_'.uniqid(),
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->batimentB = Batiment::create([
            'nom' => 'Bâtiment Mathématiques P8',
            'code' => 'BATB_P8_'.uniqid(),
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->groupe = Group::create([
            'nom' => 'Groupe L3 Informatique P8',
            'code' => 'L3_INFO_P8_'.uniqid(),
            'niveau' => 'L3',
            'capacite' => 30,
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->schedule = Schedule::create([
            'annee_universitaire_id' => $this->annee->id,
            'semestre' => 1,
            'groupe_id' => $this->groupe->id,
            'nom' => 'Emploi du temps L3 P8',
            'statut' => 'brouillon',
            'actif' => true,
        ]);

        $codeAlgo = 'ALG_P8_'.substr(uniqid(), -6);
        $this->matiereAlgo = Matiere::create([
            'code' => $codeAlgo,
            'nom' => 'Algorithmique P8',
            'niveau' => 'L3',
            'semestre' => 1,
            'faculte_id' => $this->faculte->id,
        ]);

        // Enseignant titulaire (malade ou indisponible)
        $this->profTitulaire = Teacher::create([
            'matricule' => 'ENS-P8-TIT-'.substr(uniqid(), -6),
            'nom' => 'Ben Ammar',
            'prenom' => 'Tarek',
            'email' => 'tarek.benammar@test.tn',
            'qualifications' => [$codeAlgo],
            'charge_statutaire' => 192.0,
            'actif' => true,
        ]);

        // Remplaçant 1 : Qualifié, actif et disponible
        $this->profRemplacantLibre = Teacher::create([
            'matricule' => 'ENS-P8-REM1-'.substr(uniqid(), -6),
            'nom' => 'Karray',
            'prenom' => 'Leila',
            'email' => 'leila.karray@test.tn',
            'qualifications' => [$codeAlgo],
            'charge_statutaire' => 192.0,
            'actif' => true,
        ]);

        // Remplaçant 2 : Non qualifié pour la matière Algo
        $this->profNonQualifie = Teacher::create([
            'matricule' => 'ENS-P8-REM2-'.substr(uniqid(), -6),
            'nom' => 'Masmoudi',
            'prenom' => 'Foued',
            'email' => 'foued.masmoudi@test.tn',
            'qualifications' => ['AUTRE_MATIERE'],
            'charge_statutaire' => 192.0,
            'actif' => true,
        ]);

        // Remplaçant 3 : Qualifié mais indisponible le lundi matin
        $this->profIndisponible = Teacher::create([
            'matricule' => 'ENS-P8-REM3-'.substr(uniqid(), -6),
            'nom' => 'Jlassi',
            'prenom' => 'Nader',
            'email' => 'nader.jlassi@test.tn',
            'qualifications' => [$codeAlgo],
            'charge_statutaire' => 192.0,
            'actif' => true,
        ]);
        ScheduleAvailability::create([
            'teacher_id' => $this->profIndisponible->id,
            'day_of_week' => 'monday',
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
            'type' => 'indisponible',
            'reason' => 'Congé recherche',
        ]);

        // Salles
        $this->salleInitiale = Room::create([
            'code' => 'S1_P8_'.uniqid(),
            'nom' => 'Salle 1 P8',
            'capacite' => 35,
            'batiment' => 'Batiment A',
            'batiment_id' => $this->batimentA->id,
            'faculte_id' => $this->faculte->id,
            'heure_ouverture' => '08:00:00',
            'heure_fermeture' => '19:00:00',
            'actif' => true,
            'equipements_fixes' => ['videoprojecteur'],
        ]);

        $this->salleAlternativeMemeBat = Room::create([
            'code' => 'S2_P8_'.uniqid(),
            'nom' => 'Salle 2 (Même bât) P8',
            'capacite' => 40,
            'batiment' => 'Batiment A',
            'batiment_id' => $this->batimentA->id,
            'faculte_id' => $this->faculte->id,
            'heure_ouverture' => '08:00:00',
            'heure_fermeture' => '19:00:00',
            'actif' => true,
            'equipements_fixes' => ['videoprojecteur'],
        ]);

        $this->salleAutreBat = Room::create([
            'code' => 'S3_P8_'.uniqid(),
            'nom' => 'Salle 3 (Autre bât) P8',
            'capacite' => 50,
            'batiment' => 'Batiment B',
            'batiment_id' => $this->batimentB->id,
            'faculte_id' => $this->faculte->id,
            'heure_ouverture' => '08:00:00',
            'heure_fermeture' => '19:00:00',
            'actif' => true,
            'equipements_fixes' => ['videoprojecteur'],
        ]);

        $this->gestionnaire = User::factory()->create(['name' => 'Planificateur P8']);
        $this->repairService = app(ScheduleRepairService::class);
    }

    public function test_suggestion_remplacants_filtre_qualifies_et_disponibles(): void
    {
        $slot = ScheduleSlot::create([
            'schedule_id' => $this->schedule->id,
            'matiere_id' => $this->matiereAlgo->id,
            'teacher_id' => $this->profTitulaire->id,
            'room_id' => $this->salleInitiale->id,
            'day_of_week' => 'monday',
            'start_time' => '08:30:00',
            'end_time' => '10:00:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $remplacants = $this->repairService->suggererRemplacants($slot);

        $this->assertNotEmpty($remplacants);
        $idsRemplacants = array_column($remplacants, 'teacher_id');

        // profRemplacantLibre doit être proposé
        $this->assertContains($this->profRemplacantLibre->id, $idsRemplacants);

        // profNonQualifie et profIndisponible doivent être exclus
        $this->assertNotContains($this->profNonQualifie->id, $idsRemplacants);
        $this->assertNotContains($this->profIndisponible->id, $idsRemplacants);
        $this->assertNotContains($this->profTitulaire->id, $idsRemplacants);
    }

    public function test_remplacement_enseignant_effectue_avec_succes_et_historisation(): void
    {
        $slot = ScheduleSlot::create([
            'schedule_id' => $this->schedule->id,
            'matiere_id' => $this->matiereAlgo->id,
            'teacher_id' => $this->profTitulaire->id,
            'room_id' => $this->salleInitiale->id,
            'day_of_week' => 'monday',
            'start_time' => '08:30:00',
            'end_time' => '10:00:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $slotApres = $this->repairService->remplacerEnseignant(
            $slot,
            $this->profRemplacantLibre->id,
            $this->gestionnaire->id,
            'Remplacement d\'urgence arrêt maladie'
        );

        $this->assertSame($this->profRemplacantLibre->id, $slotApres->teacher_id);

        $change = ScheduleChange::where('schedule_slot_id', $slot->id)->latest()->first();
        $this->assertNotNull($change);
        $this->assertSame('teacher_changed', $change->change_type);
        $this->assertSame($this->profTitulaire->id, $change->old_values['teacher_id']);
        $this->assertSame($this->profRemplacantLibre->id, $change->new_values['teacher_id']);
        $this->assertSame($this->gestionnaire->id, $change->changed_by);
    }

    public function test_remplacement_enseignant_interdit_si_non_qualifie(): void
    {
        $slot = ScheduleSlot::create([
            'schedule_id' => $this->schedule->id,
            'matiere_id' => $this->matiereAlgo->id,
            'teacher_id' => $this->profTitulaire->id,
            'room_id' => $this->salleInitiale->id,
            'day_of_week' => 'monday',
            'start_time' => '08:30:00',
            'end_time' => '10:00:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('pas qualifié');

        $this->repairService->remplacerEnseignant(
            $slot,
            $this->profNonQualifie->id,
            $this->gestionnaire->id
        );
    }

    public function test_suggestion_salles_alternatives_ordonne_meme_batiment(): void
    {
        $slot = ScheduleSlot::create([
            'schedule_id' => $this->schedule->id,
            'matiere_id' => $this->matiereAlgo->id,
            'teacher_id' => $this->profTitulaire->id,
            'room_id' => $this->salleInitiale->id,
            'day_of_week' => 'monday',
            'start_time' => '08:30:00',
            'end_time' => '10:00:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $alternatives = $this->repairService->suggererSallesAlternatives($slot);

        $this->assertNotEmpty($alternatives);

        // La première alternative recommandée doit être dans le même bâtiment
        $premiereSalle = $alternatives[0];
        $this->assertSame($this->salleAlternativeMemeBat->id, $premiereSalle['room_id']);
        $this->assertTrue($premiereSalle['meme_batiment']);
    }

    public function test_remplacement_salle_reussi_avec_historisation(): void
    {
        $slot = ScheduleSlot::create([
            'schedule_id' => $this->schedule->id,
            'matiere_id' => $this->matiereAlgo->id,
            'teacher_id' => $this->profTitulaire->id,
            'room_id' => $this->salleInitiale->id,
            'day_of_week' => 'monday',
            'start_time' => '08:30:00',
            'end_time' => '10:00:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $slotApres = $this->repairService->remplacerSalle(
            $slot,
            $this->salleAlternativeMemeBat->id,
            $this->gestionnaire->id,
            'Infiltration d\'eau dans la salle 1'
        );

        $this->assertSame($this->salleAlternativeMemeBat->id, $slotApres->room_id);

        $change = ScheduleChange::where('schedule_slot_id', $slot->id)->latest()->first();
        $this->assertNotNull($change);
        $this->assertSame('room_changed', $change->change_type);
        $this->assertSame($this->salleInitiale->id, $change->old_values['room_id']);
        $this->assertSame($this->salleAlternativeMemeBat->id, $change->new_values['room_id']);
    }

    public function test_diagnostic_conflit_creneau_propose_solutions_claires(): void
    {
        $slot = ScheduleSlot::create([
            'schedule_id' => $this->schedule->id,
            'matiere_id' => $this->matiereAlgo->id,
            'teacher_id' => $this->profTitulaire->id,
            'room_id' => $this->salleInitiale->id,
            'day_of_week' => 'monday',
            'start_time' => '08:30:00',
            'end_time' => '10:00:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $diagnostic = $this->repairService->diagnostiquerEtProposerSolutions($slot);

        $this->assertSame($slot->id, $diagnostic['slot_id']);
        $this->assertIsArray($diagnostic['remplacants_suggeres']);
        $this->assertIsArray($diagnostic['salles_suggerees']);
    }
}
