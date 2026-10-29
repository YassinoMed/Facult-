<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Batiment;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\Teacher;
use App\Modules\Schedule\Models\TimeSlot;
use App\Modules\Schedule\Services\ScheduleCalendarService;
use App\Modules\Schedule\Services\ScheduleConflictService;
use App\Modules\Schedule\Services\ScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests exhaustifs de PHASE 1 : Modèle métier, calendriers et ressources.
 */
class SchedulePhase1CalendrierRessourcesTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Batiment $batiment;

    private Room $salle;

    private Group $groupe;

    private Teacher $enseignant;

    private Matiere $matiere;

    private Schedule $schedule;

    private ScheduleCalendarService $calendarService;

    private ScheduleConflictService $conflictService;

    private ScheduleService $scheduleService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'code' => 'FST',
            'nom' => 'Faculté des Sciences de Tunis',
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->batiment = Batiment::create([
            'code' => 'BAT-A',
            'nom' => 'Bâtiment Informatique & Mathématiques',
            'faculte_id' => $this->faculte->id,
        ]);

        $this->salle = Room::create([
            'code' => 'INFO-101',
            'nom' => 'Laboratoire Réseaux',
            'capacite' => 30,
            'campus' => 'Campus El Manar',
            'batiment' => 'Bâtiment A',
            'batiment_id' => $this->batiment->id,
            'etage' => '1er étage',
            'type_salle' => 'labo',
            'accessible_pmr' => true,
            'heure_ouverture' => '08:00',
            'heure_fermeture' => '18:30',
            'equipements_fixes' => ['videoprojecteur', '30_pcs', 'climatisation'],
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->groupe = Group::create([
            'code' => 'L3-INFO-G1',
            'nom' => 'L3 Informatique Groupe 1',
            'capacite' => 28,
            'niveau' => 'L3',
            'filiere' => 'Informatique',
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->enseignant = Teacher::create([
            'matricule' => 'ENS-7701',
            'nom' => 'Ben Salah',
            'prenom' => 'Karim',
            'email' => 'karim.bensalah@fst.utm.tn',
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->matiere = Matiere::create([
            'code' => 'INF301',
            'nom' => 'Architecture Réseaux et Systèmes',
            'niveau' => 'L3',
            'semestre' => 1,
            'faculte_id' => $this->faculte->id,
        ]);

        $this->schedule = Schedule::create([
            'annee_universitaire_id' => $this->annee->id,
            'semestre' => 1,
            'groupe_id' => $this->groupe->id,
            'nom' => 'Emploi L3 Info S1',
            'statut' => 'brouillon',
            'actif' => true,
            'starts_at' => '2026-09-14',
            'ends_at' => '2027-01-15',
        ]);

        $this->calendarService = app(ScheduleCalendarService::class);
        $this->conflictService = app(ScheduleConflictService::class);
        $this->scheduleService = app(ScheduleService::class);
    }

    // ─────────────────────────────────────────────────────────────
    // 1. Grille horaire et pauses institutionnelles
    // ─────────────────────────────────────────────────────────────

    public function test_creation_creneaux_grille_et_scopes(): void
    {
        $slot1 = TimeSlot::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'M1',
            'nom' => '08:30 - 10:00',
            'start_time' => '08:30',
            'end_time' => '10:00',
            'type' => 'course',
            'is_mandatory_break' => false,
            'ordre' => 1,
            'actif' => true,
        ]);

        $pause = TimeSlot::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'PAUSE_MIDI',
            'nom' => 'Pause Déjeuner Institutionnelle',
            'start_time' => '12:30',
            'end_time' => '13:30',
            'type' => 'lunch',
            'is_mandatory_break' => true,
            'ordre' => 3,
            'actif' => true,
        ]);

        $this->assertSame(90, $slot1->dureeMinutes());
        $this->assertSame(60, $pause->dureeMinutes());
        $this->assertTrue($pause->estPause());
        $this->assertFalse($slot1->estPause());

        $this->assertTrue($slot1->chevauche('09:00', '10:30'));
        $this->assertFalse($slot1->chevauche('10:00', '11:30')); // Contigu, pas de chevauchement

        $cours = TimeSlot::cours()->get();
        $this->assertCount(1, $cours);
        $this->assertSame('M1', $cours->first()->code);

        $pauses = TimeSlot::pauses()->get();
        $this->assertCount(1, $pauses);
        $this->assertSame('PAUSE_MIDI', $pauses->first()->code);
    }

    public function test_detection_conflit_chevauchement_pause_obligatoire(): void
    {
        TimeSlot::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'PAUSE_DEJ',
            'nom' => 'Pause Déjeuner',
            'start_time' => '12:30',
            'end_time' => '13:30',
            'type' => 'lunch',
            'is_mandatory_break' => true,
            'actif' => true,
        ]);

        // Séance de 12:00 à 14:00 traversant la pause
        $erreurs = $this->calendarService->verifierPausesObligatoires('12:00', '14:00', 'monday', $this->faculte->id);
        $this->assertNotEmpty($erreurs);
        $this->assertStringContainsString('chevauche une pause obligatoire', $erreurs[0]);

        // Séance contiguë s'arrêtant exactement au début de la pause : OK
        $erreursContigu = $this->calendarService->verifierPausesObligatoires('11:00', '12:30', 'monday', $this->faculte->id);
        $this->assertEmpty($erreursContigu);

        // Validation intervalle invalide (fin <= début)
        $erreursInvalides = $this->calendarService->verifierPausesObligatoires('14:00', '12:00', 'monday', $this->faculte->id);
        $this->assertNotEmpty($erreursInvalides);
    }

    public function test_blocage_creation_slot_en_conflit_avec_pause(): void
    {
        TimeSlot::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'PAUSE_10H',
            'nom' => 'Pause Matinale',
            'start_time' => '10:00',
            'end_time' => '10:15',
            'type' => 'break',
            'is_mandatory_break' => true,
            'actif' => true,
        ]);

        $resultat = $this->scheduleService->creerCreneau($this->schedule, [
            'matiere_id' => $this->matiere->id,
            'teacher_id' => $this->enseignant->id,
            'room_id' => $this->salle->id,
            'day_of_week' => 'monday',
            'start_time' => '09:30',
            'end_time' => '11:00', // traverse 10:00 - 10:15
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $this->assertArrayHasKey('erreurs', $resultat);
        $this->assertStringContainsString('pause obligatoire', $resultat['erreurs'][0]);
        $this->assertDatabaseCount('schedule_slots', 0);
    }

    // ─────────────────────────────────────────────────────────────
    // 2. Semaines pédagogiques et parité
    // ─────────────────────────────────────────────────────────────

    public function test_generation_automatique_semaines_pedagogiques(): void
    {
        $semaines = $this->calendarService->genererSemainesAutomatiques(
            annee: $this->annee,
            semestre: 1,
            dateDebut: '2026-09-14', // Lundi 14 septembre 2026
            nbSemaines: 14,
            semestreLmdId: null,
            semainesVacances: [8],   // Semaine 8 = vacances
            semainesExamens: [14],   // Semaine 14 = examens
        );

        $this->assertCount(14, $semaines);

        // Vérification de la parité alternée
        $s1 = $semaines->firstWhere('numero_semaine', 1);
        $s2 = $semaines->firstWhere('numero_semaine', 2);
        $s8 = $semaines->firstWhere('numero_semaine', 8);
        $s14 = $semaines->firstWhere('numero_semaine', 14);

        $this->assertSame('impaire', $s1->parite);
        $this->assertSame('cours', $s1->type);
        $this->assertTrue($s1->estOuvree());

        $this->assertSame('paire', $s2->parite);
        $this->assertTrue($s2->correspondAParite('paire'));
        $this->assertFalse($s2->correspondAParite('impaire'));
        $this->assertTrue($s2->correspondAParite('toutes'));

        // Semaine de vacances
        $this->assertSame('vacances', $s8->type);
        $this->assertTrue($s8->estVacances());
        $this->assertFalse($s8->estOuvree());

        // Semaine d'examens
        $this->assertSame('examens', $s14->type);
        $this->assertFalse($s14->estOuvree());

        // Détection de semaine par date
        $semaineTrouvee = $this->calendarService->semainePourDate('2026-09-16', $this->annee->id);
        $this->assertNotNull($semaineTrouvee);
        $this->assertSame(1, $semaineTrouvee->numero_semaine);
    }

    public function test_cas_rare_semestre_commencant_en_milieu_de_semaine(): void
    {
        // Semestre débutant un mercredi (2026-09-16)
        $semaines = $this->calendarService->genererSemainesAutomatiques(
            annee: $this->annee,
            semestre: 1,
            dateDebut: '2026-09-16', // Mercredi
            nbSemaines: 4,
        );

        $s1 = $semaines->first();
        $this->assertSame('2026-09-16', $s1->date_debut->format('Y-m-d'));
        // La fin de semaine doit être le dimanche correspondant
        $this->assertSame('2026-09-20', $s1->date_fin->format('Y-m-d'));

        // Semaine 2 doit commencer le lundi suivant
        $s2 = $semaines->get(1);
        $this->assertSame('2026-09-21', $s2->date_debut->format('Y-m-d'));
        $this->assertSame('2026-09-27', $s2->date_fin->format('Y-m-d'));
    }

    public function test_cas_rare_annee_chevauchant_deux_annees_civiles(): void
    {
        // Période couvrant décembre 2026 et janvier 2027
        $semaines = $this->calendarService->genererSemainesAutomatiques(
            annee: $this->annee,
            semestre: 1,
            dateDebut: '2026-12-21',
            nbSemaines: 3,
        );

        $s1 = $semaines->get(0); // 21-27 déc 2026
        $s2 = $semaines->get(1); // 28 déc 2026 - 03 janv 2027
        $s3 = $semaines->get(2); // 04-10 janv 2027

        $this->assertSame('2026-12-21', $s1->date_debut->format('Y-m-d'));
        $this->assertSame('2026-12-28', $s2->date_debut->format('Y-m-d'));
        $this->assertSame('2027-01-03', $s2->date_fin->format('Y-m-d'));
        $this->assertSame('2027-01-04', $s3->date_debut->format('Y-m-d'));

        $this->assertTrue($s2->contientDate('2027-01-01'));
        $this->assertTrue($s2->contientDate('2026-12-31'));
    }

    // ─────────────────────────────────────────────────────────────
    // 3. Ressources physiques, Bâtiments et Salles
    // ─────────────────────────────────────────────────────────────

    public function test_relations_batiment_salle_et_localisation(): void
    {
        $this->assertInstanceOf(Batiment::class, $this->salle->batimentRelation);
        $this->assertSame('Bâtiment Informatique & Mathématiques', $this->salle->batimentRelation->nom);

        $sallesDuBatiment = $this->batiment->rooms;
        $this->assertCount(1, $sallesDuBatiment);
        $this->assertSame('INFO-101', $sallesDuBatiment->first()->code);

        // Localisation enrichie avec le nom du bâtiment
        $this->assertSame('Campus El Manar · Bâtiment Informatique & Mathématiques · 1er étage', $this->salle->localisation());
    }

    public function test_respect_horaires_ouverture_salle(): void
    {
        // Salle ouverte 08:00 - 18:30
        $this->assertTrue($this->salle->estOuvertePlage('08:30', '10:00'));
        $this->assertTrue($this->salle->estOuvertePlage('08:00', '18:30'));

        // Cours trop tôt (07:30 < 08:00)
        $this->assertFalse($this->salle->estOuvertePlage('07:30', '09:00'));

        // Cours trop tard (19:00 > 18:30)
        $this->assertFalse($this->salle->estOuvertePlage('17:00', '19:00'));

        // Conflit détecté via ScheduleConflictService
        $erreurs = $this->conflictService->conflitsHorairesSalle($this->salle->id, '18:00', '19:30');
        $this->assertNotEmpty($erreurs);
        $this->assertStringContainsString("n'est pas ouverte sur la plage demandée", $erreurs[0]);
    }

    public function test_blocage_reservation_salle_en_dehors_horaires_ouverture(): void
    {
        $resultat = $this->scheduleService->creerCreneau($this->schedule, [
            'matiere_id' => $this->matiere->id,
            'teacher_id' => $this->enseignant->id,
            'room_id' => $this->salle->id,
            'day_of_week' => 'tuesday',
            'start_time' => '18:00',
            'end_time' => '19:30', // dépasse 18:30
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $this->assertArrayHasKey('erreurs', $resultat);
        $this->assertStringContainsString("n'est pas ouverte", $resultat['erreurs'][0]);
        $this->assertDatabaseCount('schedule_slots', 0);
    }

    public function test_equipements_et_accessibilite_pmr(): void
    {
        $this->assertTrue($this->salle->accessible_pmr);
        $this->assertSame('labo', $this->salle->type_salle);
        $this->assertIsArray($this->salle->equipements_fixes);
        $this->assertContains('videoprojecteur', $this->salle->equipements_fixes);
        $this->assertContains('30_pcs', $this->salle->equipements_fixes);
    }
}
