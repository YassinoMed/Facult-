<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Batiment;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Enseignement\Models\SousGroupe;
use App\Modules\Enseignement\Models\TypeEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\Teacher;
use App\Modules\Schedule\Services\ScheduleConflictService;
use App\Modules\Schedule\Services\SchedulePedagogieService;
use App\Modules\Schedule\Services\ScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests exhaustifs de PHASE 2 : Séances, groupes, enseignants et équipements avancés.
 */
class SchedulePhase2SeancesRessourcesAvanceesTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Batiment $batiment;

    private Room $grandeSalle;

    private Room $petiteSalle;

    private Group $groupe;

    private SousGroupe $sousGroupe1;

    private SousGroupe $sousGroupe2;

    private Teacher $profPrincipal;

    private Teacher $profAssistant;

    private Matiere $matiereAlgo;

    private Matiere $matiereMath;

    private Enseignement $enseignement;

    private Schedule $schedule;

    private ScheduleConflictService $conflictService;

    private ScheduleService $scheduleService;

    private SchedulePedagogieService $pedagogieService;

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
            'code' => 'BAT-INFO',
            'nom' => 'Bâtiment Informatique',
            'faculte_id' => $this->faculte->id,
        ]);

        $this->grandeSalle = Room::create([
            'code' => 'AMPHI-A',
            'nom' => 'Amphithéâtre Turing',
            'capacite' => 100,
            'batiment_id' => $this->batiment->id,
            'type_salle' => 'amphi',
            'accessible_pmr' => true,
            'heure_ouverture' => '08:00',
            'heure_fermeture' => '19:00',
            'equipements_fixes' => ['videoprojecteur', 'micro', 'sonorisation', 'tableau_blanc'],
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->petiteSalle = Room::create([
            'code' => 'LABO-TP1',
            'nom' => 'Laboratoire Algorithmique',
            'capacite' => 20,
            'batiment_id' => $this->batiment->id,
            'type_salle' => 'tp',
            'accessible_pmr' => true,
            'heure_ouverture' => '08:00',
            'heure_fermeture' => '19:00',
            'equipements_fixes' => ['20_pcs', 'videoprojecteur', 'reseau_local'],
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->groupe = Group::create([
            'code' => 'L3-INFO',
            'nom' => 'L3 Informatique',
            'capacite' => 35,
            'niveau' => 'L3',
            'filiere' => 'Informatique',
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->sousGroupe1 = SousGroupe::create([
            'groupe_id' => $this->groupe->id,
            'code' => 'L3-INFO-TP1',
            'nom' => 'Sous-groupe TP 1',
            'type' => 'tp',
            'capacite' => 18,
            'actif' => true,
        ]);

        $this->sousGroupe2 = SousGroupe::create([
            'groupe_id' => $this->groupe->id,
            'code' => 'L3-INFO-TP2',
            'nom' => 'Sous-groupe TP 2',
            'type' => 'tp',
            'capacite' => 17,
            'actif' => true,
        ]);

        $this->profPrincipal = Teacher::create([
            'matricule' => 'ENS-8001',
            'nom' => 'Gharbi',
            'prenom' => 'Monia',
            'email' => 'monia.gharbi@fst.utm.tn',
            'charge_statutaire' => 192.0,
            'charge_hebdo_min' => 4.0,
            'charge_hebdo_max' => 15.0,
            'qualifications' => ['INF301', 'ALGO_AVANCEE'],
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->profAssistant = Teacher::create([
            'matricule' => 'ENS-8002',
            'nom' => 'Trabelsi',
            'prenom' => 'Sami',
            'email' => 'sami.trabelsi@fst.utm.tn',
            'charge_statutaire' => 192.0,
            'charge_hebdo_min' => 4.0,
            'charge_hebdo_max' => 18.0,
            'qualifications' => ['INF301'],
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->matiereAlgo = Matiere::create([
            'code' => 'INF301',
            'nom' => 'Algorithmique Avancée',
            'niveau' => 'L3',
            'semestre' => 1,
            'faculte_id' => $this->faculte->id,
        ]);

        $this->matiereMath = Matiere::create([
            'code' => 'MAT201',
            'nom' => 'Analyse Numérique',
            'niveau' => 'L3',
            'semestre' => 1,
            'faculte_id' => $this->faculte->id,
        ]);

        $typeEns = TypeEnseignement::create([
            'code' => 'STANDARD',
            'nom' => 'Enseignement Standard',
            'actif' => true,
        ]);

        $this->enseignement = Enseignement::create([
            'code' => 'ENS-ALGO-2026',
            'type_id' => $typeEns->id,
            'matiere_id' => $this->matiereAlgo->id,
            'annee_universitaire_id' => $this->annee->id,
            'volume_cm' => 28,
            'volume_td' => 14,
            'volume_tp' => 14,
            'statut' => 'planned',
        ]);

        $this->schedule = Schedule::create([
            'annee_universitaire_id' => $this->annee->id,
            'semestre' => 1,
            'groupe_id' => $this->groupe->id,
            'nom' => 'Planning L3 Info S1',
            'statut' => 'brouillon',
            'actif' => true,
            'starts_at' => '2026-09-14',
            'ends_at' => '2027-01-15',
        ]);

        $this->conflictService = app(ScheduleConflictService::class);
        $this->scheduleService = app(ScheduleService::class);
        $this->pedagogieService = app(SchedulePedagogieService::class);
    }

    // ─────────────────────────────────────────────────────────────
    // 1. Liaison Enseignement et suivi des volumes
    // ─────────────────────────────────────────────────────────────

    public function test_creation_slot_avec_enseignement_deduction_matiere(): void
    {
        $res = $this->scheduleService->creerCreneau($this->schedule, [
            'enseignement_id' => $this->enseignement->id,
            'teacher_id' => $this->profPrincipal->id,
            'room_id' => $this->grandeSalle->id,
            'day_of_week' => 'monday',
            'start_time' => '08:30',
            'end_time' => '10:30',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $this->assertArrayHasKey('slot', $res);
        $slot = $res['slot'];
        $this->assertSame($this->enseignement->id, $slot->enseignement_id);
        $this->assertSame($this->matiereAlgo->id, $slot->matiere_id); // Auto-résolu depuis enseignement
        $this->assertSame(2.0, $slot->dureeHeures());

        $bilan = $this->pedagogieService->bilanVolumes($this->enseignement, $this->schedule->id);
        $this->assertSame(28.0, $bilan['cm']['prevu']);
        $this->assertSame(28.0, $bilan['cm']['planifie']); // 2.0h * 14 semaines
        $this->assertSame(0.0, $bilan['cm']['reste']);
        $this->assertSame('complet', $bilan['cm']['statut']);
    }

    // ─────────────────────────────────────────────────────────────
    // 2. Séances par sous-groupes TD/TP et capacité ajustée
    // ─────────────────────────────────────────────────────────────

    public function test_sous_groupe_autorise_en_petite_salle_alors_que_groupe_entier_bloque(): void
    {
        // 1. Essai de planifier le groupe entier (35 étudiants) dans la petite salle (20 places) -> BLOQUÉ
        $resGroupeEntier = $this->scheduleService->creerCreneau($this->schedule, [
            'matiere_id' => $this->matiereAlgo->id,
            'teacher_id' => $this->profPrincipal->id,
            'room_id' => $this->petiteSalle->id,
            'day_of_week' => 'tuesday',
            'start_time' => '08:30',
            'end_time' => '10:00',
            'type' => 'td',
            'status' => 'scheduled',
        ]);

        $this->assertArrayHasKey('erreurs', $resGroupeEntier);
        $this->assertStringContainsString('Capacité dépassée', $resGroupeEntier['erreurs'][0]);

        // 2. Planification pour le sous-groupe 1 (18 étudiants <= 20 places) -> ACCEPTÉ
        $resSousGroupe = $this->scheduleService->creerCreneau($this->schedule, [
            'matiere_id' => $this->matiereAlgo->id,
            'sous_groupe_id' => $this->sousGroupe1->id,
            'teacher_id' => $this->profPrincipal->id,
            'room_id' => $this->petiteSalle->id,
            'day_of_week' => 'tuesday',
            'start_time' => '08:30',
            'end_time' => '10:00',
            'type' => 'tp',
            'status' => 'scheduled',
        ]);

        $this->assertArrayHasKey('slot', $resSousGroupe);
        $this->assertSame($this->sousGroupe1->id, $resSousGroupe['slot']->sous_groupe_id);
    }

    public function test_concomitance_autorisee_entre_deux_sous_groupes_distincts(): void
    {
        // Sous-groupe 1 en TP avec Prof Principal dans Petite Salle
        $res1 = $this->scheduleService->creerCreneau($this->schedule, [
            'matiere_id' => $this->matiereAlgo->id,
            'sous_groupe_id' => $this->sousGroupe1->id,
            'teacher_id' => $this->profPrincipal->id,
            'room_id' => $this->petiteSalle->id,
            'day_of_week' => 'wednesday',
            'start_time' => '10:00',
            'end_time' => '12:00',
            'type' => 'tp',
            'status' => 'scheduled',
        ]);
        $this->assertArrayHasKey('slot', $res1);

        // Sous-groupe 2 en TP avec Prof Assistant dans Grande Salle au MÊME horaire -> ACCEPTÉ (groupes disjoints)
        $res2 = $this->scheduleService->creerCreneau($this->schedule, [
            'matiere_id' => $this->matiereAlgo->id,
            'sous_groupe_id' => $this->sousGroupe2->id,
            'teacher_id' => $this->profAssistant->id,
            'room_id' => $this->grandeSalle->id,
            'day_of_week' => 'wednesday',
            'start_time' => '10:00',
            'end_time' => '12:00',
            'type' => 'tp',
            'status' => 'scheduled',
        ]);
        $this->assertArrayHasKey('slot', $res2);

        // En revanche, une tentative de double-réservation du MÊME sous-groupe (sousGroupe1) -> BLOQUÉ
        $resDouble = $this->scheduleService->creerCreneau($this->schedule, [
            'matiere_id' => $this->matiereMath->id,
            'sous_groupe_id' => $this->sousGroupe1->id,
            'teacher_id' => $this->profAssistant->id,
            'room_id' => $this->grandeSalle->id,
            'day_of_week' => 'wednesday',
            'start_time' => '10:00',
            'end_time' => '12:00',
            'type' => 'td',
            'status' => 'scheduled',
        ]);
        $this->assertArrayHasKey('erreurs', $resDouble);
        $this->assertStringContainsString('occupé', $resDouble['erreurs'][0]);
    }

    // ─────────────────────────────────────────────────────────────
    // 3. Parité des semaines (Quinzaine paire / impaire)
    // ─────────────────────────────────────────────────────────────

    public function test_quinzaine_paire_et_impaire_coexistent_sur_la_meme_salle_et_meme_enseignant(): void
    {
        // Créneau sur semaines PAIRES (lundi 14:00 - 16:00, petite salle, prof principal)
        $resPaire = $this->scheduleService->creerCreneau($this->schedule, [
            'matiere_id' => $this->matiereAlgo->id,
            'sous_groupe_id' => $this->sousGroupe1->id,
            'teacher_id' => $this->profPrincipal->id,
            'room_id' => $this->petiteSalle->id,
            'day_of_week' => 'monday',
            'semaine_parite' => 'paire',
            'start_time' => '14:00',
            'end_time' => '16:00',
            'type' => 'tp',
            'status' => 'scheduled',
        ]);
        $this->assertArrayHasKey('slot', $resPaire);

        // Créneau sur semaines IMPAIRES au MÊME horaire, MÊME salle, MÊME enseignant (sous-groupe 2) -> AUCUN CONFLIT !
        $resImpaire = $this->scheduleService->creerCreneau($this->schedule, [
            'matiere_id' => $this->matiereAlgo->id,
            'sous_groupe_id' => $this->sousGroupe2->id,
            'teacher_id' => $this->profPrincipal->id,
            'room_id' => $this->petiteSalle->id,
            'day_of_week' => 'monday',
            'semaine_parite' => 'impaire',
            'start_time' => '14:00',
            'end_time' => '16:00',
            'type' => 'tp',
            'status' => 'scheduled',
        ]);
        $this->assertArrayHasKey('slot', $resImpaire);

        // En revanche, un créneau sur semaines 'paire' au même horaire -> BLOQUÉ
        $resConflitPaire = $this->scheduleService->creerCreneau($this->schedule, [
            'matiere_id' => $this->matiereMath->id,
            'sous_groupe_id' => $this->sousGroupe1->id,
            'teacher_id' => $this->profAssistant->id,
            'room_id' => $this->petiteSalle->id,
            'day_of_week' => 'monday',
            'semaine_parite' => 'paire',
            'start_time' => '14:00',
            'end_time' => '16:00',
            'type' => 'tp',
            'status' => 'scheduled',
        ]);
        $this->assertArrayHasKey('erreurs', $resConflitPaire);
    }

    // ─────────────────────────────────────────────────────────────
    // 4. Co-enseignement et multi-enseignants
    // ─────────────────────────────────────────────────────────────

    public function test_co_enseignement_et_detection_indisponibilite_coenseignant(): void
    {
        // Bloquer prof assistant le jeudi 10:00 - 12:00 sur un créneau dans un autre groupe
        $autreGroupe = Group::create([
            'code' => 'L2-INFO',
            'nom' => 'L2 Info',
            'capacite' => 30,
            'niveau' => 'L2',
            'filiere' => 'Info',
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);
        $autreSchedule = Schedule::create([
            'annee_universitaire_id' => $this->annee->id,
            'semestre' => 1,
            'groupe_id' => $autreGroupe->id,
            'nom' => 'Planning L2',
            'statut' => 'brouillon',
            'actif' => true,
        ]);
        $this->scheduleService->creerCreneau($autreSchedule, [
            'matiere_id' => $this->matiereAlgo->id,
            'teacher_id' => $this->profAssistant->id,
            'day_of_week' => 'thursday',
            'start_time' => '10:00',
            'end_time' => '12:00',
            'type' => 'td',
            'status' => 'scheduled',
        ]);

        // Tentative de créer une séance de TP avec Prof Principal ET Prof Assistant en co-enseignant le jeudi 10:00 - 12:00
        $resCo = $this->scheduleService->creerCreneau($this->schedule, [
            'matiere_id' => $this->matiereAlgo->id,
            'teacher_id' => $this->profPrincipal->id,
            'room_id' => $this->grandeSalle->id,
            'day_of_week' => 'thursday',
            'start_time' => '10:00',
            'end_time' => '12:00',
            'type' => 'tp',
            'status' => 'scheduled',
            'co_enseignants' => [$this->profAssistant->id],
        ]);

        $this->assertArrayHasKey('erreurs', $resCo);
        $this->assertStringContainsString('Enseignant occupé', $resCo['erreurs'][0]);
    }

    // ─────────────────────────────────────────────────────────────
    // 5. Adéquation des équipements requis
    // ─────────────────────────────────────────────────────────────

    public function test_verification_equipements_requis_de_salle(): void
    {
        // 1. Séance exigeant un réseau local dans l'Amphi Turing (qui n'a pas de réseau local) -> BLOQUÉ
        $resManquant = $this->scheduleService->creerCreneau($this->schedule, [
            'matiere_id' => $this->matiereAlgo->id,
            'teacher_id' => $this->profPrincipal->id,
            'room_id' => $this->grandeSalle->id,
            'day_of_week' => 'friday',
            'start_time' => '08:30',
            'end_time' => '10:30',
            'type' => 'tp',
            'status' => 'scheduled',
            'equipements_requis' => ['videoprojecteur', 'reseau_local'],
        ]);

        $this->assertArrayHasKey('erreurs', $resManquant);
        $this->assertStringContainsString('Équipements requis manquants', $resManquant['erreurs'][0]);
        $this->assertStringContainsString('reseau_local', $resManquant['erreurs'][0]);

        // 2. Mêmes équipements dans la petite salle (qui a bien reseau_local et videoprojecteur) -> ACCEPTÉ
        $resOk = $this->scheduleService->creerCreneau($this->schedule, [
            'matiere_id' => $this->matiereAlgo->id,
            'sous_groupe_id' => $this->sousGroupe1->id,
            'teacher_id' => $this->profPrincipal->id,
            'room_id' => $this->petiteSalle->id,
            'day_of_week' => 'friday',
            'start_time' => '08:30',
            'end_time' => '10:30',
            'type' => 'tp',
            'status' => 'scheduled',
            'equipements_requis' => ['videoprojecteur', 'reseau_local'],
        ]);

        $this->assertArrayHasKey('slot', $resOk);
    }

    // ─────────────────────────────────────────────────────────────
    // 6. Qualifications et charge horaire enseignant
    // ─────────────────────────────────────────────────────────────

    public function test_rejet_enseignant_non_qualifie(): void
    {
        // Prof Assistant est qualifié seulement pour INF301, pas pour MAT201
        $res = $this->scheduleService->creerCreneau($this->schedule, [
            'matiere_id' => $this->matiereMath->id,
            'teacher_id' => $this->profAssistant->id,
            'room_id' => $this->grandeSalle->id,
            'day_of_week' => 'friday',
            'start_time' => '14:00',
            'end_time' => '16:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $this->assertArrayHasKey('erreurs', $res);
        $this->assertStringContainsString("n'est pas qualifié", $res['erreurs'][0]);
    }

    public function test_analyse_charge_hebdomadaire_et_surcharge(): void
    {
        // Ajouter un créneau hebdomadaire de 2h
        $this->scheduleService->creerCreneau($this->schedule, [
            'matiere_id' => $this->matiereAlgo->id,
            'teacher_id' => $this->profPrincipal->id,
            'room_id' => $this->grandeSalle->id,
            'day_of_week' => 'friday',
            'start_time' => '14:00',
            'end_time' => '16:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $analyse = $this->pedagogieService->analyseChargeEnseignant($this->profPrincipal);
        $this->assertSame(2.0, $analyse['charge_hebdo']);
        $this->assertTrue($analyse['sous_charge']); // 2.0h < 4.0h min
        $this->assertFalse($analyse['surcharge']);
    }
}
