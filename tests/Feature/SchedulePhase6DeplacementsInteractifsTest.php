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
use App\Modules\Schedule\Models\ScheduleChange;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use App\Modules\Schedule\Services\ScheduleInteractiveService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulePhase6DeplacementsInteractifsTest extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculte;

    protected Group $groupe;

    protected AnneeUniversitaire $annee;

    protected Batiment $batimentA;

    protected Batiment $batimentB;

    protected Schedule $schedule;

    protected Matiere $matiereAlgo;

    protected Matiere $matiereWeb;

    protected Teacher $profAlgo;

    protected Teacher $profWeb;

    protected Room $salle101;

    protected Room $salleLab;

    protected User $gestionnaire;

    protected ScheduleInteractiveService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences Test P6',
            'code' => 'FST_P6_'.uniqid(),
            'actif' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027-P6-'.uniqid(),
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->batimentA = Batiment::create([
            'nom' => 'Bâtiment A P6',
            'code' => 'BATA_P6_'.uniqid(),
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->batimentB = Batiment::create([
            'nom' => 'Bâtiment B P6',
            'code' => 'BATB_P6_'.uniqid(),
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->groupe = Group::create([
            'nom' => 'Groupe L3 Info G1 P6',
            'code' => 'L3_G1_P6_'.uniqid(),
            'niveau' => 'L3',
            'capacite' => 30,
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->schedule = Schedule::create([
            'annee_universitaire_id' => $this->annee->id,
            'semestre' => 1,
            'groupe_id' => $this->groupe->id,
            'nom' => 'Emploi L3 Info S1 P6',
            'statut' => 'brouillon',
            'actif' => true,
        ]);

        $this->matiereAlgo = Matiere::create([
            'code' => 'ALGO_P6_'.uniqid(),
            'nom' => 'Algorithmique Avancée P6',
            'niveau' => 'L3',
            'semestre' => 1,
            'faculte_id' => $this->faculte->id,
        ]);

        $this->matiereWeb = Matiere::create([
            'code' => 'WEB_P6_'.uniqid(),
            'nom' => 'Développement Web P6',
            'niveau' => 'L3',
            'semestre' => 1,
            'faculte_id' => $this->faculte->id,
        ]);

        $this->profAlgo = Teacher::create([
            'matricule' => 'ENS-P6-001-'.uniqid(),
            'nom' => 'Ben Salem',
            'prenom' => 'Karim',
            'email' => 'karim.bensalem@test.tn',
            'charge_statutaire' => 192.0,
            'qualifications' => ['ALGO_P6'],
            'actif' => true,
        ]);

        $this->profWeb = Teacher::create([
            'matricule' => 'ENS-P6-002-'.uniqid(),
            'nom' => 'Trabelsi',
            'prenom' => 'Sonia',
            'email' => 'sonia.trabelsi@test.tn',
            'charge_statutaire' => 192.0,
            'qualifications' => ['WEB_P6'],
            'actif' => true,
        ]);

        $this->salle101 = Room::create([
            'code' => 'S101_P6_'.uniqid(),
            'nom' => 'Salle 101 P6',
            'capacite' => 40,
            'actif' => true,
            'batiment' => 'Batiment A',
            'batiment_id' => $this->batimentA->id,
            'faculte_id' => $this->faculte->id,
            'heure_ouverture' => '08:00:00',
            'heure_fermeture' => '19:00:00',
            'equipements_fixes' => ['videoprojecteur'],
        ]);

        $this->salleLab = Room::create([
            'code' => 'SLAB_P6_'.uniqid(),
            'nom' => 'Lab Machine P6',
            'capacite' => 20,
            'actif' => true,
            'batiment' => 'Batiment B',
            'batiment_id' => $this->batimentB->id,
            'faculte_id' => $this->faculte->id,
            'heure_ouverture' => '08:00:00',
            'heure_fermeture' => '18:00:00',
            'equipements_fixes' => ['videoprojecteur', 'ordinateurs'],
        ]);

        $this->gestionnaire = User::factory()->create(['name' => 'Planificateur P6']);
        $this->service = app(ScheduleInteractiveService::class);
    }

    public function test_previsualisation_deplacement_valide_retourne_statut_optimal(): void
    {
        $slot = ScheduleSlot::create([
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

        $resultat = $this->service->previsualiserDeplacement(
            $slot,
            'tuesday',
            '10:15:00',
            '11:45:00',
            $this->salle101->id
        );

        $this->assertTrue($resultat['faisable']);
        $this->assertEmpty($resultat['conflits_durs']);
        $this->assertSame('optimal', $resultat['recommandation']);
    }

    public function test_previsualisation_bloque_si_enseignant_occupe_ou_indisponible(): void
    {
        $slot1 = ScheduleSlot::create([
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

        // Autre cours du même prof le mardi matin
        ScheduleSlot::create([
            'schedule_id' => $this->schedule->id,
            'matiere_id' => $this->matiereWeb->id,
            'teacher_id' => $this->profAlgo->id,
            'room_id' => $this->salleLab->id,
            'day_of_week' => 'tuesday',
            'start_time' => '10:00:00',
            'end_time' => '12:00:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        // Tentative de déplacer slot1 sur le créneau du mardi où le prof est déjà pris
        $resultat = $this->service->previsualiserDeplacement(
            $slot1,
            'tuesday',
            '10:30:00',
            '12:00:00',
            $this->salle101->id
        );

        $this->assertFalse($resultat['faisable']);
        $this->assertNotEmpty($resultat['conflits_durs']);
        $this->assertSame('bloque', $resultat['recommandation']);
    }

    public function test_previsualisation_bloque_si_salle_fermee_ou_equipement_manquant(): void
    {
        $slot = ScheduleSlot::create([
            'schedule_id' => $this->schedule->id,
            'matiere_id' => $this->matiereWeb->id,
            'teacher_id' => $this->profWeb->id,
            'room_id' => $this->salleLab->id,
            'day_of_week' => 'monday',
            'start_time' => '08:30:00',
            'end_time' => '10:00:00',
            'type' => 'tp',
            'equipements_requis' => ['ordinateurs'],
            'status' => 'scheduled',
        ]);

        // Tentative de déplacement vers salle101 (qui n'a pas d'ordinateurs)
        $resultat = $this->service->previsualiserDeplacement(
            $slot,
            'wednesday',
            '09:00:00',
            '11:00:00',
            $this->salle101->id
        );

        $this->assertFalse($resultat['faisable']);
        $this->assertStringContainsString('équipements requis', implode(' ', $resultat['conflits_durs']));

        // Tentative hors horaires d'ouverture (20h)
        $resultatNuit = $this->service->previsualiserDeplacement(
            $slot,
            'wednesday',
            '19:30:00',
            '21:00:00',
            $this->salleLab->id
        );

        $this->assertFalse($resultatNuit['faisable']);
        $this->assertStringContainsString('ferme à', implode(' ', $resultatNuit['conflits_durs']));
    }

    public function test_previsualisation_alerte_si_dejeuner_ou_capacite(): void
    {
        $slot = ScheduleSlot::create([
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

        // Déplacement sur 12h30 - 14h00 (empiètement déjeuner) dans salleLab (20 places vs groupe capacite 30)
        $resultat = $this->service->previsualiserDeplacement(
            $slot,
            'thursday',
            '12:30:00',
            '14:00:00',
            $this->salleLab->id
        );

        $this->assertTrue($resultat['faisable']); // Faisable car ce sont des soft constraints
        $this->assertSame('acceptable_avec_reserves', $resultat['recommandation']);
        $this->assertNotEmpty($resultat['alertes_qualite']);
        $this->assertLessThan(0, $resultat['impact_score']);
    }

    public function test_deplacement_creneau_reussi_avec_historisation_schedule_change(): void
    {
        $slot = ScheduleSlot::create([
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

        $slotApres = $this->service->deplacerCreneau(
            $slot,
            'friday',
            '14:00:00',
            '15:30:00',
            $this->salle101->id,
            $this->gestionnaire->id,
            'Ajustement à la demande du délégué'
        );

        $this->assertSame('friday', $slotApres->day_of_week);
        $this->assertSame('14:00:00', $slotApres->start_time);
        $this->assertSame('15:30:00', $slotApres->end_time);

        $change = ScheduleChange::where('schedule_slot_id', $slot->id)->latest()->first();
        $this->assertNotNull($change);
        $this->assertSame('time_changed', $change->change_type);
        $this->assertSame($this->gestionnaire->id, $change->changed_by);
        $this->assertSame('Ajustement à la demande du délégué', $change->reason);
        $this->assertSame('monday', $change->old_values['day_of_week']);
        $this->assertSame('friday', $change->new_values['day_of_week']);
    }

    public function test_deplacement_interdit_sur_planning_verrouille(): void
    {
        $this->schedule->update(['statut' => 'verrouille']);

        $slot = ScheduleSlot::create([
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

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Impossible de modifier un planning verrouillé');

        $this->service->deplacerCreneau(
            $slot,
            'tuesday',
            '09:00:00',
            '10:30:00'
        );
    }

    public function test_echange_atomique_de_deux_creneaux_swap(): void
    {
        $slotAlgo = ScheduleSlot::create([
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

        $slotWeb = ScheduleSlot::create([
            'schedule_id' => $this->schedule->id,
            'matiere_id' => $this->matiereWeb->id,
            'teacher_id' => $this->profWeb->id,
            'room_id' => $this->salle101->id,
            'day_of_week' => 'thursday',
            'start_time' => '14:00:00',
            'end_time' => '15:30:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $resultat = $this->service->echangerCreneaux(
            $slotAlgo,
            $slotWeb,
            $this->gestionnaire->id,
            'Inversion Algo et Web'
        );

        $this->assertSame('thursday', $resultat['slot_a']->day_of_week);
        $this->assertSame('14:00:00', $resultat['slot_a']->start_time);

        $this->assertSame('monday', $resultat['slot_b']->day_of_week);
        $this->assertSame('08:30:00', $resultat['slot_b']->start_time);

        $this->assertDatabaseHas('schedule_changes', [
            'schedule_slot_id' => $slotAlgo->id,
            'change_type' => 'time_changed',
        ]);
        $this->assertDatabaseHas('schedule_changes', [
            'schedule_slot_id' => $slotWeb->id,
            'change_type' => 'time_changed',
        ]);
    }

    public function test_redimensionnement_de_duree_creneau(): void
    {
        $slot = ScheduleSlot::create([
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

        $slotRedim = $this->service->redimensionnerCreneau(
            $slot,
            '10:30:00',
            $this->gestionnaire->id,
            'Extension de 30min pour TD'
        );

        $this->assertSame('10:30:00', $slotRedim->end_time);
        $this->assertSame(120, $slotRedim->dureeMinutes());
    }
}
