<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Models\Surveillance;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleAvailability;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use App\Modules\Schedule\Services\ExamScheduleEngineService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP-06 — vérification indépendante de la planification des examens.
 *
 * Ce fichier est distinct de `PlanificationAutomatiqueExamensTest` et du
 * rapport LOOP-06 préexistant. Il prouve la vulnérabilité critique de
 * `appliquerScenario()` (scénario client non revérifié) ainsi que les
 * défauts `$effectif` et `auditerSessionExamens()`, puis sert de
 * non-régression aux correctifs.
 */
class Loop06PlanificationExamensVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Group $groupe1;

    private Group $groupe2;

    private Room $salleOk;

    private Room $salleMoyenne;

    private Room $sallePetite;

    private Room $salleInactive;

    private Room $salleFermee;

    private Matiere $matiereAlgo;

    private Matiere $matiereWeb;

    private Teacher $prof1;

    private Teacher $prof2;

    private Teacher $profInactif;

    private SessionExamen $session;

    private Examen $examen;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculte = Faculte::create([
            'nom' => 'Faculté Vérification LOOP-06',
            'code' => 'FAC-VL06',
            'description' => 'Vérification LOOP-06',
            'active' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027 Vérification LOOP-06',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'LIC-VL06',
            'nom' => 'Licence Vérification LOOP-06',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
            'actif' => true,
        ]);

        $niveau = Niveau::create([
            'programme_id' => $this->programme->id,
            'code' => 'L3-VL06',
            'nom' => 'Licence 3 Vérification LOOP-06',
            'ordre' => 3,
            'actif' => true,
        ]);

        $semestre = SemestreLmd::create([
            'niveau_id' => $niveau->id,
            'code' => 'S5-VL06',
            'nom' => 'Semestre 5 Vérification LOOP-06',
            'numero' => 5,
            'ordre' => 5,
            'actif' => true,
        ]);

        $ue = UniteEnseignement::create([
            'code' => 'UE-VL06',
            'nom' => 'UE Vérification LOOP-06',
            'programme_id' => $this->programme->id,
            'semestre_id' => $semestre->id,
            'credits' => 6,
            'coefficient' => 2,
            'type' => 'fondamentale',
            'actif' => true,
        ]);

        $this->matiereAlgo = Matiere::create([
            'code' => 'ALG-VL06',
            'nom' => 'Algorithmique Vérification LOOP-06',
            'ue_id' => $ue->id,
            'niveau' => 'L3',
            'semestre' => 5,
            'credits' => 3,
            'coefficient' => 1.5,
            'active' => true,
        ]);

        $this->matiereWeb = Matiere::create([
            'code' => 'WEB-VL06',
            'nom' => 'Développement Web Vérification LOOP-06',
            'ue_id' => $ue->id,
            'niveau' => 'L3',
            'semestre' => 5,
            'credits' => 3,
            'coefficient' => 1.5,
            'active' => true,
        ]);

        $this->groupe1 = Group::create([
            'nom' => 'Groupe 1 Vérification LOOP-06',
            'code' => 'GRP-VL06-G1',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L3',
            'capacite' => 30,
            'actif' => true,
        ]);

        $this->groupe2 = Group::create([
            'nom' => 'Groupe 2 Vérification LOOP-06',
            'code' => 'GRP-VL06-G2',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L3',
            'capacite' => 20,
            'actif' => true,
        ]);

        $this->salleOk = Room::create([
            'code' => 'SALLE-VL06-OK',
            'nom' => 'Salle Vérification LOOP-06',
            'capacite' => 100,
            'min_surveillants' => 1,
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->salleMoyenne = Room::create([
            'code' => 'SALLE-VL06-MOY',
            'nom' => 'Salle Moyenne Vérification LOOP-06',
            'capacite' => 60,
            'min_surveillants' => 1,
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->sallePetite = Room::create([
            'code' => 'SALLE-VL06-PETITE',
            'nom' => 'Salle Petite Vérification LOOP-06',
            'capacite' => 20,
            'min_surveillants' => 1,
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->salleInactive = Room::create([
            'code' => 'SALLE-VL06-INACTIVE',
            'nom' => 'Salle Inactive Vérification LOOP-06',
            'capacite' => 100,
            'min_surveillants' => 1,
            'faculte_id' => $this->faculte->id,
            'actif' => false,
        ]);

        $this->salleFermee = Room::create([
            'code' => 'SALLE-VL06-FERMEE',
            'nom' => 'Salle Fermée Vérification LOOP-06',
            'capacite' => 100,
            'min_surveillants' => 1,
            'faculte_id' => $this->faculte->id,
            'actif' => true,
            'heure_ouverture' => '08:00:00',
            'heure_fermeture' => '10:00:00',
        ]);

        $this->prof1 = Teacher::create([
            'nom' => 'Enseignant',
            'prenom' => 'Un',
            'email' => 'loop06.prof1@universite.test',
            'actif' => true,
        ]);

        $this->prof2 = Teacher::create([
            'nom' => 'Enseignant',
            'prenom' => 'Deux',
            'email' => 'loop06.prof2@universite.test',
            'actif' => true,
        ]);

        $this->profInactif = Teacher::create([
            'nom' => 'Enseignant',
            'prenom' => 'Inactif',
            'email' => 'loop06.prof.inactif@universite.test',
            'actif' => false,
        ]);

        $this->session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS-VL06',
            'nom' => 'Session Vérification LOOP-06',
            'type' => 'principale',
            'date_debut' => '2027-01-18',
            'date_fin' => '2027-01-30',
            'statut' => 'open',
        ]);

        $this->examen = Examen::create([
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'groupe_id' => $this->groupe1->id,
            'libelle' => 'Examens Vérification LOOP-06',
            'type' => 'examen',
            'statut' => 'draft',
        ]);
    }

    public function test_application_refuse_un_scenario_incomplet(): void
    {
        $this->creerEpreuve($this->matiereAlgo);
        $this->creerEpreuve($this->matiereWeb);

        $scenario = $this->genererScenarioValide();
        array_pop($scenario['placements']);

        $this->attendreRefus(
            fn () => $this->appliquer($scenario),
            'Scénario incomplet'
        );
    }

    public function test_application_refuse_une_epreuve_hors_examen(): void
    {
        $this->creerEpreuve($this->matiereAlgo);
        $this->creerEpreuve($this->matiereWeb);
        $etranger = $this->creerExamenEtranger($this->matiereAlgo);

        $scenario = $this->genererScenarioValide();
        $scenario['placements'][0]['epreuve_id'] = $etranger->id;

        $this->attendreRefus(
            fn () => $this->appliquer($scenario),
            "n'appartient pas à cet examen"
        );

        $this->assertSame('2027-01-01', $etranger->fresh()->date->format('Y-m-d'));
    }

    public function test_application_refuse_une_epreuve_dupliquee(): void
    {
        $this->creerEpreuve($this->matiereAlgo);
        $this->creerEpreuve($this->matiereWeb);

        $scenario = $this->genererScenarioValide();
        $scenario['placements'][1]['epreuve_id'] = $scenario['placements'][0]['epreuve_id'];

        $this->attendreRefus(
            fn () => $this->appliquer($scenario),
            'présente plusieurs fois'
        );
    }

    public function test_application_refuse_une_salle_inactive(): void
    {
        $this->creerEpreuve($this->matiereAlgo);
        $this->creerEpreuve($this->matiereWeb);

        $scenario = $this->genererScenarioValide();
        $scenario['placements'][0]['salle_id'] = $this->salleInactive->id;

        $this->attendreRefus(
            fn () => $this->appliquer($scenario),
            'inactive'
        );
    }

    public function test_application_refuse_une_salle_d_une_autre_faculte(): void
    {
        $this->creerEpreuve($this->matiereAlgo);
        $this->creerEpreuve($this->matiereWeb);

        $autreFaculte = Faculte::create([
            'nom' => 'Faculté Hors Périmètre LOOP-06',
            'code' => 'FAC-VL06-HORS',
            'description' => 'Hors périmètre',
            'active' => true,
        ]);

        $salleAutreFaculte = Room::create([
            'code' => 'SALLE-VL06-HORS',
            'nom' => 'Salle Hors Périmètre',
            'capacite' => 100,
            'min_surveillants' => 1,
            'faculte_id' => $autreFaculte->id,
            'actif' => true,
        ]);

        $scenario = $this->genererScenarioValide();
        $scenario['placements'][0]['salle_id'] = $salleAutreFaculte->id;

        $this->attendreRefus(
            fn () => $this->appliquer($scenario),
            'autre faculté'
        );
    }

    public function test_application_refuse_une_salle_fermee_hors_plage(): void
    {
        $this->creerEpreuve($this->matiereAlgo);
        $this->creerEpreuve($this->matiereWeb);

        $scenario = $this->genererScenarioValide();
        $scenario['placements'][0]['salle_id'] = $this->salleFermee->id;
        $scenario['placements'][0]['heure_debut'] = '14:30:00';
        $scenario['placements'][0]['heure_fin'] = '16:30:00';

        $this->attendreRefus(
            fn () => $this->appliquer($scenario),
            'fermée sur cette plage'
        );
    }

    public function test_application_refuse_une_capacite_insuffisante(): void
    {
        $this->creerEpreuve($this->matiereAlgo);
        $this->creerEpreuve($this->matiereWeb);

        $scenario = $this->genererScenarioValide();
        $scenario['placements'][0]['salle_id'] = $this->sallePetite->id;

        $this->attendreRefus(
            fn () => $this->appliquer($scenario),
            'Capacité utile insuffisante'
        );
    }

    public function test_application_refuse_une_collision_de_salle_en_base(): void
    {
        $this->creerEpreuve($this->matiereAlgo);
        $this->creerEpreuve($this->matiereWeb);

        $scenario = $this->genererScenarioValide();
        $scenario['placements'][0]['date'] = '2027-01-22';
        $scenario['placements'][0]['heure_debut'] = '16:00:00';
        $scenario['placements'][0]['heure_fin'] = '18:00:00';
        $scenario['placements'][0]['salle_id'] = $this->salleOk->id;

        $this->creerEpreuve(
            $this->matiereAlgo,
            examen: $this->creerExamenBrouillon('EXAM-VL06-CONFLIT-SALLE'),
            groupe: $this->groupe2,
            salle: $this->salleOk,
            date: '2027-01-22',
            debut: '16:00:00',
            fin: '18:00:00',
            statut: 'scheduled'
        );

        $this->attendreRefus(
            fn () => $this->appliquer($scenario),
            'Conflit en base'
        );
    }

    public function test_application_refuse_une_collision_de_groupe_en_base(): void
    {
        $this->creerEpreuve($this->matiereAlgo);
        $this->creerEpreuve($this->matiereWeb);

        $scenario = $this->genererScenarioValide();
        $scenario['placements'][0]['date'] = '2027-01-22';
        $scenario['placements'][0]['heure_debut'] = '16:00:00';
        $scenario['placements'][0]['heure_fin'] = '18:00:00';
        $scenario['placements'][0]['salle_id'] = $this->salleOk->id;

        $this->creerEpreuve(
            $this->matiereAlgo,
            examen: $this->creerExamenBrouillon('EXAM-VL06-CONFLIT-GROUPE'),
            groupe: $this->groupe1,
            salle: $this->salleMoyenne,
            date: '2027-01-22',
            debut: '16:00:00',
            fin: '18:00:00',
            statut: 'scheduled'
        );

        $this->attendreRefus(
            fn () => $this->appliquer($scenario),
            'Conflit en base'
        );
    }

    public function test_application_refuse_une_collision_entre_placements_du_scenario(): void
    {
        $this->creerEpreuve($this->matiereAlgo);
        $this->creerEpreuve($this->matiereWeb);

        $scenario = $this->genererScenarioValide();
        $scenario['placements'][0]['date'] = '2027-01-22';
        $scenario['placements'][0]['heure_debut'] = '16:00:00';
        $scenario['placements'][0]['heure_fin'] = '18:00:00';
        $scenario['placements'][0]['salle_id'] = $this->salleOk->id;

        $scenario['placements'][1]['date'] = '2027-01-22';
        $scenario['placements'][1]['heure_debut'] = '16:00:00';
        $scenario['placements'][1]['heure_fin'] = '18:00:00';
        $scenario['placements'][1]['salle_id'] = $this->salleMoyenne->id;

        $this->attendreRefus(
            fn () => $this->appliquer($scenario),
            'Collision groupe'
        );
    }

    public function test_application_refuse_une_indisponibilite_du_groupe(): void
    {
        $this->creerEpreuve($this->matiereAlgo);
        $this->creerEpreuve($this->matiereWeb);

        $scenario = $this->genererScenarioValide();
        $scenario['placements'][0]['date'] = '2027-01-19';
        $scenario['placements'][0]['heure_debut'] = '11:00:00';
        $scenario['placements'][0]['heure_fin'] = '13:00:00';
        $scenario['placements'][0]['salle_id'] = $this->salleOk->id;

        ScheduleAvailability::create([
            'annee_universitaire_id' => $this->annee->id,
            'group_id' => $this->groupe1->id,
            'day_of_week' => 'tuesday',
            'start_time' => '11:00:00',
            'end_time' => '13:00:00',
            'type' => 'indisponible',
            'reason' => 'Réunion pédagogique',
        ]);

        $this->attendreRefus(
            fn () => $this->appliquer($scenario),
            'indisponible'
        );
    }

    public function test_application_refuse_un_cours_regulier_du_groupe(): void
    {
        $this->creerEpreuve($this->matiereAlgo);
        $this->creerEpreuve($this->matiereWeb);

        $scenario = $this->genererScenarioValide();
        $scenario['placements'][0]['date'] = '2027-01-18';
        $scenario['placements'][0]['heure_debut'] = '08:30:00';
        $scenario['placements'][0]['heure_fin'] = '10:30:00';
        $scenario['placements'][0]['salle_id'] = $this->salleOk->id;

        $this->creerCoursRegulier('monday', '08:30:00', '10:30:00');

        $this->attendreRefus(
            fn () => $this->appliquer($scenario),
            'cours régulier'
        );
    }

    public function test_application_refuse_un_surveillant_inactif(): void
    {
        $this->creerEpreuve($this->matiereAlgo);
        $this->creerEpreuve($this->matiereWeb);

        $scenario = $this->genererScenarioValide();
        $scenario['placements'][0]['surveillants'] = [$this->profInactif->id];

        $this->attendreRefus(
            fn () => $this->appliquer($scenario),
            'inactif'
        );
    }

    public function test_application_refuse_un_surveillant_deja_mobilise(): void
    {
        $this->creerEpreuve($this->matiereAlgo);
        $this->creerEpreuve($this->matiereWeb);

        $scenario = $this->genererScenarioValide();
        $scenario['placements'][0]['date'] = '2027-01-22';
        $scenario['placements'][0]['heure_debut'] = '16:00:00';
        $scenario['placements'][0]['heure_fin'] = '18:00:00';
        $scenario['placements'][0]['salle_id'] = $this->salleOk->id;
        $scenario['placements'][0]['surveillants'] = [$this->prof1->id];

        $epreuveConflit = $this->creerEpreuve(
            $this->matiereAlgo,
            examen: $this->creerExamenBrouillon('EXAM-VL06-PROF-OCCUPE'),
            groupe: $this->groupe2,
            salle: $this->salleMoyenne,
            date: '2027-01-22',
            debut: '16:00:00',
            fin: '18:00:00',
            statut: 'scheduled'
        );

        Surveillance::create([
            'epreuve_id' => $epreuveConflit->id,
            'teacher_id' => $this->prof1->id,
            'salle_id' => $this->salleMoyenne->id,
            'role' => 'principal',
            'statut' => 'assigne',
        ]);

        $this->attendreRefus(
            fn () => $this->appliquer($scenario),
            'déjà mobilisé'
        );
    }

    public function test_application_refuse_une_epreuve_verrouillee_sans_forcage(): void
    {
        $epreuve = $this->creerEpreuve($this->matiereAlgo);
        $this->creerEpreuve($this->matiereWeb);

        $scenario = $this->genererScenarioValide();
        $epreuve->update(['statut' => 'locked']);

        $this->attendreRefus(
            fn () => $this->appliquer($scenario),
            'verrouillée'
        );
    }

    public function test_planifier_epreuve_sans_salle_ne_leve_pas_de_variable_indefinie(): void
    {
        $avertissements = [];

        set_error_handler(function (int $niveau, string $message) use (&$avertissements): bool {
            $avertissements[] = $message;

            return true;
        });

        try {
            $epreuve = app(ExamScheduleEngineService::class)->planifierEpreuve([
                'session_id' => $this->session->id,
                'annee_universitaire_id' => $this->annee->id,
                'examen_id' => $this->examen->id,
                'matiere_id' => $this->matiereAlgo->id,
                'groupe_id' => $this->groupe1->id,
                'date' => '2027-02-01',
                'heure_debut' => '09:00',
                'heure_fin' => '11:00',
            ]);
        } finally {
            restore_error_handler();
        }

        // Sans salle, l'effectif reste celui du groupe (30), jamais un
        // repli arbitraire à 25 qui sous-estimerait la capacité requise.
        $this->assertSame(30, (int) $epreuve->fresh()->capacite_necessaire);
        $this->assertNull($epreuve->fresh()->salle_id);
        $this->assertSame(
            [],
            array_values(array_filter(
                $avertissements,
                static fn (string $message): bool => str_contains($message, 'Undefined variable')
            ))
        );
    }

    public function test_audit_ignore_les_epreuves_annulees(): void
    {
        $active = $this->creerEpreuve(
            $this->matiereAlgo,
            salle: $this->salleOk,
            date: '2027-03-01',
            debut: '09:00:00',
            fin: '11:00:00',
            statut: 'scheduled'
        );

        Surveillance::create([
            'epreuve_id' => $active->id,
            'teacher_id' => $this->prof1->id,
            'salle_id' => $this->salleOk->id,
            'role' => 'principal',
            'statut' => 'assigne',
        ]);

        Epreuve::create([
            'code' => 'EPR-VL06-ANNULEE',
            'examen_id' => $this->examen->id,
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->matiereWeb->id,
            'groupe_id' => $this->groupe1->id,
            'salle_id' => null,
            'date' => '2027-03-02',
            'heure_debut' => '09:00:00',
            'heure_fin' => '11:00:00',
            'statut' => 'cancelled',
        ]);

        $audit = app(ExamScheduleEngineService::class)->auditerSessionExamens($this->examen);

        $this->assertTrue($audit['conforme']);
        $this->assertSame(1, $audit['nb_epreuves']);
        $this->assertSame(0, $audit['epreuves_sans_salle']);
        $this->assertSame([], $audit['conflits_bloquants']);
    }

    public function test_audit_detecte_une_salle_inactive(): void
    {
        $this->creerEpreuvePlanifieeAvecSurveillant();

        $this->salleOk->update(['actif' => false]);

        $audit = app(ExamScheduleEngineService::class)->auditerSessionExamens($this->examen);

        $this->assertFalse($audit['conforme']);
        $this->assertStringContainsString('inactive', implode(' ', $audit['conflits_bloquants']));
    }

    public function test_audit_detecte_une_salle_fermee(): void
    {
        $this->creerEpreuvePlanifieeAvecSurveillant();

        $this->salleOk->update([
            'heure_ouverture' => '08:00:00',
            'heure_fermeture' => '09:00:00',
        ]);

        $audit = app(ExamScheduleEngineService::class)->auditerSessionExamens($this->examen);

        $this->assertFalse($audit['conforme']);
        $this->assertStringContainsString('fermée', implode(' ', $audit['conflits_bloquants']));
    }

    public function test_audit_detecte_une_indisponibilite_du_groupe(): void
    {
        $this->creerEpreuvePlanifieeAvecSurveillant();

        ScheduleAvailability::create([
            'annee_universitaire_id' => $this->annee->id,
            'group_id' => $this->groupe1->id,
            'day_of_week' => 'monday',
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'type' => 'indisponible',
            'reason' => 'Indisponibilité de vérification',
        ]);

        $audit = app(ExamScheduleEngineService::class)->auditerSessionExamens($this->examen);

        $this->assertFalse($audit['conforme']);
        $this->assertStringContainsString('indisponible', implode(' ', $audit['conflits_bloquants']));
    }

    public function test_audit_detecte_un_cours_regulier_du_groupe(): void
    {
        $this->creerEpreuvePlanifieeAvecSurveillant();

        $this->creerCoursRegulier('monday', '09:00:00', '11:00:00');

        $audit = app(ExamScheduleEngineService::class)->auditerSessionExamens($this->examen);

        $this->assertFalse($audit['conforme']);
        $this->assertStringContainsString('cours régulier', implode(' ', $audit['conflits_bloquants']));
    }

    public function test_application_valide_puis_audit_conforme_et_reapplication_idempotente(): void
    {
        $ep1 = $this->creerEpreuve($this->matiereAlgo);
        $ep2 = $this->creerEpreuve($this->matiereWeb);

        $scenario = $this->genererScenarioValide();

        // Le scénario moteur ne pré-affecte pas les surveillants : on en
        // fournit un par épreuve pour que l'audit post-application porte sur
        // l'ordonnancement et non sur une sous-surveillance attendue.
        $scenario['placements'][0]['surveillants'] = [$this->prof1->id];
        $scenario['placements'][1]['surveillants'] = [$this->prof2->id];

        $bilan = $this->appliquer($scenario);
        $this->assertSame(2, $bilan['epreuves_mises_a_jour']);
        $this->assertSame('scheduled', $bilan['statut_examen']);

        $audit = app(ExamScheduleEngineService::class)->auditerSessionExamens($this->examen);
        $this->assertTrue($audit['conforme'], implode(' | ', $audit['conflits_bloquants']));

        // Réapplication du même scénario : les anciennes positions des
        // épreuves re-planifiées ne doivent pas être vues comme des conflits.
        $bilan2 = $this->appliquer($scenario);
        $this->assertSame(2, $bilan2['epreuves_mises_a_jour']);

        $this->assertSame(2, Epreuve::where('examen_id', $this->examen->id)->count());
        $this->assertSame('scheduled', $ep1->fresh()->statut);
        $this->assertSame('scheduled', $ep2->fresh()->statut);
        $this->assertTrue(app(ExamScheduleEngineService::class)->auditerSessionExamens($this->examen)['conforme']);
    }

    /**
     * @return array<string, mixed>
     */
    private function genererScenarioValide(): array
    {
        $resultat = app(ExamScheduleEngineService::class)->genererScenariosPlanning($this->examen, [
            'date_debut' => '2027-01-18',
            'date_fin' => '2027-01-25',
        ]);

        $scenario = $resultat['scenarios'][ExamScheduleEngineService::SCENARIO_ETALE];

        $this->assertTrue($scenario['est_valide'], implode(' | ', $scenario['anomalies_impossibles'] ?? []));
        $this->assertCount(2, $scenario['placements']);

        return $scenario;
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @return array<string, mixed>
     */
    private function appliquer(array $scenario, bool $forcer = false): array
    {
        return app(ExamScheduleEngineService::class)->appliquerScenario(
            $this->examen,
            (string) $scenario['scenario_id'],
            $scenario,
            null,
            $forcer
        );
    }

    private function attendreRefus(callable $action, string $messageAttendu): void
    {
        try {
            $action();
        } catch (DomainException $e) {
            $this->assertStringContainsString($messageAttendu, $e->getMessage());

            return;
        }

        $this->fail("Le scénario aurait dû être refusé avec le motif « {$messageAttendu} ».");
    }

    private function creerEpreuve(
        Matiere $matiere,
        ?Examen $examen = null,
        ?Group $groupe = null,
        ?Room $salle = null,
        string $date = '2027-01-01',
        string $debut = '00:00:00',
        string $fin = '02:00:00',
        string $statut = 'draft'
    ): Epreuve {
        $examen ??= $this->examen;
        $groupe ??= $this->groupe1;

        return Epreuve::create([
            'code' => 'EPR-VL06-'.strtoupper(substr(uniqid(), -8)),
            'examen_id' => $examen->id,
            'session_id' => $examen->session_id,
            'annee_universitaire_id' => $examen->annee_universitaire_id,
            'matiere_id' => $matiere->id,
            'groupe_id' => $groupe->id,
            'salle_id' => $salle?->id,
            'date' => $date,
            'heure_debut' => $debut,
            'heure_fin' => $fin,
            'capacite_necessaire' => $groupe->capacite,
            'statut' => $statut,
        ]);
    }

    private function creerEpreuvePlanifieeAvecSurveillant(): Epreuve
    {
        $epreuve = $this->creerEpreuve(
            $this->matiereAlgo,
            salle: $this->salleOk,
            date: '2027-03-01',
            debut: '09:00:00',
            fin: '11:00:00',
            statut: 'scheduled'
        );

        Surveillance::create([
            'epreuve_id' => $epreuve->id,
            'teacher_id' => $this->prof1->id,
            'salle_id' => $this->salleOk->id,
            'role' => 'principal',
            'statut' => 'assigne',
        ]);

        return $epreuve;
    }

    private function creerExamenBrouillon(string $code): Examen
    {
        return Examen::create([
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'groupe_id' => $this->groupe2->id,
            'code' => $code,
            'libelle' => 'Examen secondaire '.$code,
            'type' => 'examen',
            'statut' => 'draft',
        ]);
    }

    private function creerExamenEtranger(Matiere $matiere): Epreuve
    {
        return $this->creerEpreuve(
            $matiere,
            examen: $this->creerExamenBrouillon('EXAM-VL06-ETRANGER'),
            groupe: $this->groupe2
        );
    }

    private function creerCoursRegulier(string $jour, string $debut, string $fin): ScheduleSlot
    {
        $planning = Schedule::create([
            'annee_universitaire_id' => $this->annee->id,
            'semestre' => 5,
            'groupe_id' => $this->groupe1->id,
            'nom' => 'Emploi du temps Vérification LOOP-06',
            'actif' => true,
        ]);

        return ScheduleSlot::create([
            'schedule_id' => $planning->id,
            'matiere_id' => $this->matiereAlgo->id,
            'teacher_id' => null,
            'room_id' => null,
            'day_of_week' => $jour,
            'start_time' => $debut,
            'end_time' => $fin,
            'type' => 'course',
            'status' => 'scheduled',
        ]);
    }
}
