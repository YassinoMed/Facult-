<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Batiment;
use App\Models\Faculte;
use App\Models\IntegrationLog;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Jobs\PlanifierExamensJob;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\ExamenService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\ScheduleAvailability;
use App\Modules\Schedule\Models\Teacher;
use App\Modules\Schedule\Services\ExamScheduleEngineService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanificationAutomatiqueExamensTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $adminAutreFaculte;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private Group $groupe1;

    private Group $groupe2;

    private Matiere $matiereAlgo;

    private Matiere $matiereWeb;

    private Matiere $matiereBase;

    private Room $amphiPrincipal;

    private Room $salleMoyenne;

    private Room $petiteSalle;

    private Teacher $prof1;

    private Teacher $prof2;

    private SessionExamen $session;

    private Examen $examen;

    private ExamScheduleEngineService $engine;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'modules.evaluation.active' => true,
            'modules.schedule.active' => true,
        ]);

        $this->faculteA = Faculte::create([
            'nom' => 'Faculté des Sciences Info',
            'code' => 'FSI_'.uniqid(),
            'actif' => true,
        ]);

        $this->faculteB = Faculte::create([
            'nom' => 'Faculté de Droit',
            'code' => 'FD_'.uniqid(),
            'actif' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin Scolarite',
            'email' => 'scolarite@test.univ',
            'password' => 'Password@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $this->adminAutreFaculte = User::creerCompteBackOffice([
            'name' => 'Admin Autre Fac',
            'email' => 'autre.fac@test.univ',
            'password' => 'Password@123',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $batiment = Batiment::create([
            'nom' => 'Bâtiment Informatique',
            'code' => 'BAT_INFO_'.uniqid(),
            'faculte_id' => $this->faculteA->id,
            'actif' => true,
        ]);

        $programme = Programme::create([
            'faculte_id' => $this->faculteA->id,
            'code' => 'L_INFO_'.uniqid(),
            'nom' => 'Licence Informatique',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
        ]);

        $niveau = Niveau::create(['programme_id' => $programme->id, 'code' => 'L3', 'nom' => 'L3', 'ordre' => 3]);
        $semestre = SemestreLmd::create(['niveau_id' => $niveau->id, 'code' => 'S5', 'nom' => 'S5', 'numero' => 5, 'ordre' => 5]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id,
            'programme_id' => $programme->id,
            'code' => 'UE_FOND',
            'nom' => 'Fondamentaux',
            'credits' => 12,
            'coefficient' => 4,
        ]);

        $this->groupe1 = Group::create([
            'nom' => 'Groupe L3 Info A',
            'code' => 'G_L3A_'.uniqid(),
            'niveau' => 'L3',
            'capacite' => 30, // 30 étudiants
            'faculte_id' => $this->faculteA->id,
            'actif' => true,
        ]);

        $this->groupe2 = Group::create([
            'nom' => 'Groupe L3 Info B',
            'code' => 'G_L3B_'.uniqid(),
            'niveau' => 'L3',
            'capacite' => 20, // 20 étudiants
            'faculte_id' => $this->faculteA->id,
            'actif' => true,
        ]);

        $this->matiereAlgo = Matiere::create([
            'code' => 'MAT_ALGO_'.substr(uniqid(), -6),
            'nom' => 'Algorithmique Avancée',
            'ue_id' => $ue->id,
            'niveau' => 'L3',
            'semestre' => 5,
            'faculte_id' => $this->faculteA->id,
        ]);

        $this->matiereWeb = Matiere::create([
            'code' => 'MAT_WEB_'.substr(uniqid(), -6),
            'nom' => 'Architecture Web',
            'ue_id' => $ue->id,
            'niveau' => 'L3',
            'semestre' => 5,
            'faculte_id' => $this->faculteA->id,
        ]);

        $this->matiereBase = Matiere::create([
            'code' => 'MAT_BD_'.substr(uniqid(), -6),
            'nom' => 'Bases de Données',
            'ue_id' => $ue->id,
            'niveau' => 'L3',
            'semestre' => 5,
            'faculte_id' => $this->faculteA->id,
        ]);

        // Amphi: 100 places nominales => 50 places examen (ratio 50%) -> accepte groupe 30 et 20
        $this->amphiPrincipal = Room::create([
            'code' => 'AMPHI_CENTRAL',
            'nom' => 'Amphithéâtre Central',
            'capacite' => 100,
            'min_surveillants' => 2,
            'batiment_id' => $batiment->id,
            'faculte_id' => $this->faculteA->id,
            'actif' => true,
        ]);

        // Salle moyenne: 60 places nominales => 30 places examen -> accepte groupe 30 ou 20
        $this->salleMoyenne = Room::create([
            'code' => 'SALLE_M20',
            'nom' => 'Salle M20',
            'capacite' => 60,
            'min_surveillants' => 1,
            'batiment_id' => $batiment->id,
            'faculte_id' => $this->faculteA->id,
            'actif' => true,
        ]);

        // Petite salle: 30 places nominales => 15 places examen -> insuffisante pour groupe 30 et 20
        $this->petiteSalle = Room::create([
            'code' => 'SALLE_P10',
            'nom' => 'Salle P10',
            'capacite' => 30,
            'min_surveillants' => 1,
            'batiment_id' => $batiment->id,
            'faculte_id' => $this->faculteA->id,
            'actif' => true,
        ]);

        $this->prof1 = Teacher::create([
            'matricule' => 'ENS_001_'.substr(uniqid(), -5),
            'nom' => 'Zahra',
            'prenom' => 'Sami',
            'email' => 'sami.zahra@univ.test',
            'faculte_id' => $this->faculteA->id,
            'actif' => true,
        ]);

        $this->prof2 = Teacher::create([
            'matricule' => 'ENS_002_'.substr(uniqid(), -5),
            'nom' => 'Gharbi',
            'prenom' => 'Monia',
            'email' => 'monia.gharbi@univ.test',
            'faculte_id' => $this->faculteA->id,
            'actif' => true,
        ]);

        $this->session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS_P12_'.substr(uniqid(), -6),
            'nom' => 'Session Principale S1',
            'type' => 'principale',
            'date_debut' => '2027-01-18',
            'date_fin' => '2027-01-30',
            'statut' => 'open',
        ]);

        $this->examen = Examen::create([
            'session_id' => $this->session->id,
            'programme_id' => $programme->id,
            'groupe_id' => $this->groupe1->id,
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'EXAM_S1_'.substr(uniqid(), -6),
            'libelle' => 'Examens S1 L3 Informatique',
            'type' => 'examen',
            'statut' => 'draft',
            'responsable_id' => $this->admin->id,
        ]);

        $this->engine = app(ExamScheduleEngineService::class);
    }

    private function creerEpreuveDraft(Matiere $matiere, ?Group $groupe = null, ?int $capa = null): Epreuve
    {
        return Epreuve::create([
            'code' => 'EPR_'.strtoupper(substr(uniqid(), -7)),
            'examen_id' => $this->examen->id,
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $matiere->id,
            'groupe_id' => $groupe?->id ?? $this->groupe1->id,
            'salle_id' => null,
            'date' => '2027-01-01',
            'heure_debut' => '00:00:00',
            'heure_fin' => '02:00:00',
            'capacite_necessaire' => $capa ?? ($groupe?->capacite ?? 30),
            'statut' => 'draft',
        ]);
    }

    public function test_generation_produit_3_scenarios_comparables_avec_scores_et_metriques(): void
    {
        $ep1 = $this->creerEpreuveDraft($this->matiereAlgo, $this->groupe1);
        $ep2 = $this->creerEpreuveDraft($this->matiereWeb, $this->groupe1);
        $ep3 = $this->creerEpreuveDraft($this->matiereBase, $this->groupe1);

        $resultat = $this->engine->genererScenariosPlanning($this->examen, [
            'date_debut' => '2027-01-18',
            'date_fin' => '2027-01-25',
        ]);

        $this->assertSame($this->examen->id, $resultat['examen_id']);
        $this->assertArrayHasKey('scenarios', $resultat);
        $this->assertArrayHasKey(ExamScheduleEngineService::SCENARIO_ETALE, $resultat['scenarios']);
        $this->assertArrayHasKey(ExamScheduleEngineService::SCENARIO_COMPACT, $resultat['scenarios']);
        $this->assertArrayHasKey(ExamScheduleEngineService::SCENARIO_AMPHI, $resultat['scenarios']);

        // Vérification de chaque scénario
        foreach ($resultat['scenarios'] as $scId => $sc) {
            $this->assertTrue($sc['est_valide'], "Le scénario {$scId} devrait être valide");
            $this->assertSame(0, $sc['nb_conflits_durs']);
            $this->assertEmpty($sc['anomalies_impossibles']);
            $this->assertCount(3, $sc['placements']);
            $this->assertGreaterThanOrEqual(70, $sc['score_qualite']);
            $this->assertSame(3, $sc['metriques']['epreuves_placees']);
            $this->assertSame(0, $sc['metriques']['epreuves_non_placees']);
        }
    }

    public function test_comparaison_scenarios_recommande_la_meilleure_variante(): void
    {
        $this->creerEpreuveDraft($this->matiereAlgo, $this->groupe1);
        $this->creerEpreuveDraft($this->matiereWeb, $this->groupe1);

        $resultat = $this->engine->genererScenariosPlanning($this->examen, [
            'date_debut' => '2027-01-18',
            'date_fin' => '2027-01-25',
        ]);

        $this->assertNotNull($resultat['recommandation']['scenario_id']);
        $this->assertGreaterThan(0, $resultat['recommandation']['score_qualite']);
        $this->assertStringContainsString('optimal', $resultat['recommandation']['motif']);
    }

    public function test_contraintes_dures_anti_collision_groupe_dans_le_planning(): void
    {
        $ep1 = $this->creerEpreuveDraft($this->matiereAlgo, $this->groupe1);
        $ep2 = $this->creerEpreuveDraft($this->matiereWeb, $this->groupe1);

        $resultat = $this->engine->genererScenariosPlanning($this->examen, [
            'date_debut' => '2027-01-18',
            'date_fin' => '2027-01-22',
        ]);

        // Pour chaque scénario, vérifier qu'aucune paire d'épreuves du même groupe ne chevauche le même créneau
        foreach ($resultat['scenarios'] as $sc) {
            $placements = $sc['placements'];
            for ($i = 0; $i < count($placements); $i++) {
                for ($j = $i + 1; $j < count($placements); $j++) {
                    $p1 = $placements[$i];
                    $p2 = $placements[$j];
                    if ($p1['date'] === $p2['date'] && $p1['groupe_id'] === $p2['groupe_id']) {
                        $chevauche = ($p1['heure_debut'] < $p2['heure_fin'] && $p1['heure_fin'] > $p2['heure_debut']);
                        $this->assertFalse($chevauche, 'Deux épreuves pour le même groupe ne doivent jamais se chevaucher.');
                    }
                }
            }
        }
    }

    public function test_contraintes_dures_anti_collision_salle_dans_le_planning(): void
    {
        // 2 groupes différents, 2 matières différentes mais une seule grande salle
        $this->salleMoyenne->update(['actif' => false]);
        $this->petiteSalle->update(['actif' => false]);

        $this->creerEpreuveDraft($this->matiereAlgo, $this->groupe1); // groupe 1 (30 candidats)
        $this->creerEpreuveDraft($this->matiereWeb, $this->groupe2);  // groupe 2 (20 candidats)

        $resultat = $this->engine->genererScenariosPlanning($this->examen, [
            'date_debut' => '2027-01-18',
            'date_fin' => '2027-01-20',
        ]);

        foreach ($resultat['scenarios'] as $sc) {
            $placements = $sc['placements'];
            for ($i = 0; $i < count($placements); $i++) {
                for ($j = $i + 1; $j < count($placements); $j++) {
                    $p1 = $placements[$i];
                    $p2 = $placements[$j];
                    if ($p1['date'] === $p2['date'] && $p1['salle_id'] === $p2['salle_id']) {
                        $chevauche = ($p1['heure_debut'] < $p2['heure_fin'] && $p1['heure_fin'] > $p2['heure_debut']);
                        $this->assertFalse($chevauche, 'La même salle ne doit jamais accueillir 2 épreuves simultanément.');
                    }
                }
            }
        }
    }

    public function test_contraintes_dures_rejet_salles_saturees_et_respect_ratio_espacement(): void
    {
        // Petite salle: 30 places nominales => 15 places examen à 50%
        // Groupe de 30 candidats : petite salle doit être STRICTEMENT rejetée
        $this->amphiPrincipal->update(['actif' => false]);
        $this->salleMoyenne->update(['actif' => false]);

        $this->creerEpreuveDraft($this->matiereAlgo, $this->groupe1, 30); // 30 candidats

        $resultat = $this->engine->genererScenariosPlanning($this->examen, [
            'date_debut' => '2027-01-18',
            'date_fin' => '2027-01-20',
        ]);

        // Tous les scénarios doivent déclarer la contrainte impossible car seule petiteSalle est active
        foreach ($resultat['scenarios'] as $sc) {
            $this->assertFalse($sc['est_valide']);
            $this->assertGreaterThan(0, $sc['nb_conflits_durs']);
            $this->assertNotEmpty($sc['anomalies_impossibles']);
            $this->assertStringContainsString('sous-capacitaire', $sc['anomalies_impossibles'][0]);
        }
    }

    public function test_contraintes_dures_respect_indisponibilites_confirmees_salle_et_groupe(): void
    {
        // 2027-01-18 est un lundi. Poser une indisponibilité sur l'amphi le lundi matin de 08:00 à 12:00
        ScheduleAvailability::create([
            'annee_universitaire_id' => $this->annee->id,
            'room_id' => $this->amphiPrincipal->id,
            'day_of_week' => 'monday',
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
            'type' => 'indisponible',
            'reason' => 'Maintenance des projecteurs',
            'cree_par' => $this->admin->id,
        ]);

        $this->salleMoyenne->update(['actif' => false]);
        $this->petiteSalle->update(['actif' => false]);

        $this->creerEpreuveDraft($this->matiereAlgo, $this->groupe1);

        $resultat = $this->engine->genererScenariosPlanning($this->examen, [
            'date_debut' => '2027-01-18',
            'date_fin' => '2027-01-22',
        ]);

        foreach ($resultat['scenarios'] as $sc) {
            foreach ($sc['placements'] as $p) {
                if ($p['salle_id'] === $this->amphiPrincipal->id && $p['date'] === '2027-01-18') {
                    // Ne doit JAMAIS être programmé le lundi matin entre 08:00 et 12:00
                    $conflit = ($p['heure_debut'] < '12:00:00' && $p['heure_fin'] > '08:00:00');
                    $this->assertFalse($conflit, 'L amphi était indisponible le lundi matin.');
                }
            }
        }
    }

    public function test_contraintes_impossibles_sont_signalees_explicitement(): void
    {
        // Désactiver toutes les salles
        $this->amphiPrincipal->update(['actif' => false]);
        $this->salleMoyenne->update(['actif' => false]);
        $this->petiteSalle->update(['actif' => false]);

        $ep = $this->creerEpreuveDraft($this->matiereAlgo, $this->groupe1);

        $resultat = $this->engine->genererScenariosPlanning($this->examen, [
            'date_debut' => '2027-01-18',
            'date_fin' => '2027-01-20',
        ]);

        $scEtale = $resultat['scenarios'][ExamScheduleEngineService::SCENARIO_ETALE];
        $this->assertFalse($scEtale['est_valide']);
        $this->assertSame(1, $scEtale['nb_conflits_durs']);
        $this->assertNotEmpty($scEtale['anomalies_impossibles']);
        $this->assertStringContainsString('Contrainte impossible', $scEtale['anomalies_impossibles'][0]);
    }

    public function test_application_scenario_invalide_est_strictement_rejetee(): void
    {
        $this->creerEpreuveDraft($this->matiereAlgo, $this->groupe1);

        $scenarioInvalide = [
            'est_valide' => false,
            'nb_conflits_durs' => 1,
            'placements' => [],
        ];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Impossible d\'appliquer un scénario invalide');

        $this->engine->appliquerScenario($this->examen, 'SCENARIO_INVALIDE', $scenarioInvalide, $this->admin->id);
    }

    public function test_application_scenario_valide_met_a_jour_epreuves_et_statut_examen(): void
    {
        $ep1 = $this->creerEpreuveDraft($this->matiereAlgo, $this->groupe1);
        $ep2 = $this->creerEpreuveDraft($this->matiereWeb, $this->groupe1);

        $resultat = $this->engine->genererScenariosPlanning($this->examen, [
            'date_debut' => '2027-01-18',
            'date_fin' => '2027-01-25',
        ]);

        $scChoisi = $resultat['scenarios'][ExamScheduleEngineService::SCENARIO_ETALE];
        $this->assertTrue($scChoisi['est_valide']);

        $bilan = $this->engine->appliquerScenario(
            $this->examen,
            ExamScheduleEngineService::SCENARIO_ETALE,
            $scChoisi,
            $this->admin->id
        );

        $this->assertTrue($bilan['succes']);
        $this->assertSame(2, $bilan['epreuves_mises_a_jour']);
        $this->assertSame('scheduled', $bilan['statut_examen']);

        // Vérification en base
        $ep1->refresh();
        $this->assertSame('scheduled', $ep1->statut);
        $this->assertNotNull($ep1->date);
        $this->assertNotNull($ep1->salle_id);
        $this->assertSame('2027-01-18', $ep1->date->format('Y-m-d'));

        // Vérification trace IntegrationLog
        $this->assertDatabaseHas('integration_logs', [
            'integration' => 'evaluation',
            'operation' => 'exam.schedule.scenario_applied',
            'status' => 'completed',
        ]);
    }

    public function test_generation_necrase_pas_un_planning_valide_ou_verrouille_sans_autorisation(): void
    {
        $this->examen->update(['statut' => 'published']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('La génération n\'écrase pas un planning validé sans autorisation');

        $this->engine->genererScenariosPlanning($this->examen, ['autoriser_ecrasement' => false]);
    }

    public function test_autorisation_explicite_autorise_ecrasement_planning_valide(): void
    {
        $ep1 = $this->creerEpreuveDraft($this->matiereAlgo, $this->groupe1);
        $this->examen->update(['statut' => 'published']);

        $resultat = $this->engine->genererScenariosPlanning($this->examen, [
            'date_debut' => '2027-01-18',
            'date_fin' => '2027-01-25',
            'autoriser_ecrasement' => true,
        ]);

        $this->assertNotEmpty($resultat['scenarios']);

        // Application avec forcer: true
        $sc = $resultat['scenarios'][ExamScheduleEngineService::SCENARIO_ETALE];
        $bilan = $this->engine->appliquerScenario(
            $this->examen,
            ExamScheduleEngineService::SCENARIO_ETALE,
            $sc,
            $this->admin->id,
            forcer: true
        );

        $this->assertTrue($bilan['succes']);
    }

    public function test_publication_bloquee_si_contrainte_dure_violee_dans_examen(): void
    {
        // VERDICT EXIGÉ PAR LE CONTRAT : FAIL si un planning est déclaré valide malgré une contrainte dure violée
        // Créer une épreuve sans salle
        $ep = Epreuve::create([
            'code' => 'EPR_SANS_SALLE',
            'examen_id' => $this->examen->id,
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->matiereAlgo->id,
            'groupe_id' => $this->groupe1->id,
            'salle_id' => null,
            'date' => '2027-01-18',
            'heure_debut' => '08:30:00',
            'heure_fin' => '10:30:00',
            'statut' => 'scheduled',
        ]);

        $this->examen->update(['statut' => 'scheduled']);

        $examenService = app(ExamenService::class);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Publication impossible : contraintes dures violées');

        // La transition vers 'published' doit lever une DomainException
        $examenService->transiter($this->examen, 'published');
    }

    public function test_publication_autorisee_si_planning_strictement_conforme(): void
    {
        $ep = Epreuve::create([
            'code' => 'EPR_CONFORME',
            'examen_id' => $this->examen->id,
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->matiereAlgo->id,
            'groupe_id' => $this->groupe1->id,
            'salle_id' => $this->amphiPrincipal->id,
            'date' => '2027-01-18',
            'heure_debut' => '08:30:00',
            'heure_fin' => '10:30:00',
            'statut' => 'scheduled',
        ]);

        // Assigner les 2 surveillants requis
        $this->engine->affecterSurveillants($ep, [$this->prof1->id, $this->prof2->id]);

        $this->examen->update(['statut' => 'scheduled']);

        $examenService = app(ExamenService::class);
        $publie = $examenService->transiter($this->examen, 'published');

        $this->assertSame('published', $publie->statut);
        $this->assertNotNull($publie->published_at);
    }

    public function test_job_asynchrone_planification_examens(): void
    {
        $this->creerEpreuveDraft($this->matiereAlgo, $this->groupe1);

        $job = new PlanifierExamensJob(
            examenId: $this->examen->id,
            scenarioId: ExamScheduleEngineService::SCENARIO_ETALE,
            options: ['date_debut' => '2027-01-18', 'date_fin' => '2027-01-25'],
            userId: $this->admin->id,
            appliquerDirectement: true
        );

        $bilan = $job->handle($this->engine);

        $this->assertTrue($bilan['succes']);
        $this->assertSame('scheduled', $this->examen->fresh()->statut);
    }

    public function test_routes_http_scenarios_et_application_avec_isolation_faculte(): void
    {
        $this->creerEpreuveDraft($this->matiereAlgo, $this->groupe1);

        // 1. Appel par admin autorisé (même faculté)
        $response = $this->actingAs($this->admin)->postJson(
            route('admin.evaluations.examens.planifier.scenarios', $this->examen),
            ['date_debut' => '2027-01-18', 'date_fin' => '2027-01-25']
        );

        $response->assertOk();
        $response->assertJsonStructure(['examen_id', 'scenarios', 'comparaison', 'recommandation']);
        $scData = $response->json('scenarios.SCENARIO_ETALE');

        // 2. Application du scénario via HTTP
        $resAppliquer = $this->actingAs($this->admin)->postJson(
            route('admin.evaluations.examens.planifier.appliquer', $this->examen),
            [
                'scenario_id' => 'SCENARIO_ETALE',
                'scenario_data' => $scData,
            ]
        );

        $resAppliquer->assertOk();
        $resAppliquer->assertJson(['succes' => true, 'statut_examen' => 'scheduled']);

        // 3. Audit conformité via HTTP
        $resAudit = $this->actingAs($this->admin)->getJson(
            route('admin.evaluations.examens.audit-conformite', $this->examen)
        );
        $resAudit->assertOk();
        // Le scénario affecte désormais les surveillants disponibles
        // (périmètre facultaire résolu via le département de l'enseignant).
        $resAudit->assertJson(['conforme' => true]);

        // 4. Isolation faculté (IDOR) : administrateur d'une autre faculté rejeté (403/404)
        $resAutre = $this->actingAs($this->adminAutreFaculte)->postJson(
            route('admin.evaluations.examens.planifier.scenarios', $this->examen)
        );
        $this->assertTrue(in_array($resAutre->status(), [403, 404], true));
    }

    public function test_concurrence_et_generation_repetee_idempotente(): void
    {
        $this->creerEpreuveDraft($this->matiereAlgo, $this->groupe1);
        $this->creerEpreuveDraft($this->matiereWeb, $this->groupe1);

        // 1ere exécution
        $res1 = $this->engine->genererScenariosPlanning($this->examen, [
            'date_debut' => '2027-01-18',
            'date_fin' => '2027-01-25',
        ]);

        // 2eme exécution immédiate : même nombre d'épreuves, pas d'état corrompu
        $res2 = $this->engine->genererScenariosPlanning($this->examen, [
            'date_debut' => '2027-01-18',
            'date_fin' => '2027-01-25',
        ]);

        $this->assertSame(
            count($res1['scenarios']['SCENARIO_ETALE']['placements']),
            count($res2['scenarios']['SCENARIO_ETALE']['placements'])
        );
        $this->assertSame(2, $this->examen->epreuves()->count());
    }
}
