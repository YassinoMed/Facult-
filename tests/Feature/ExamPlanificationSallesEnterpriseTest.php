<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Batiment;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\ExamPlanificationSallesOrchestratorService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Services\ExamScheduleEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suite de tests Enterprise pour EA-EXAMENS-03 — Planification & Allocation des Salles (Menus 4 & 5).
 *
 * Couvre :
 *  - Permissions d'accès aux Menus 4 & 5
 *  - Audit des capacités utiles d'examen avec ratio anti-fraude 50% (Décision D-04)
 *  - Salles bloquantes (capacité nulle ou non renseignée)
 *  - Génération et comparaison des 3 scénarios CSP (équilibré, compact, amphis prioritaires)
 *  - Contraintes dures : anti-collision groupe, anti-collision salle, capacité utile
 *  - Cloisonnement facultaire et anti-IDOR
 *  - Garantie zéro écriture en consultation
 */
class ExamPlanificationSallesEnterpriseTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte1;

    private Faculte $faculte2;

    private AnneeUniversitaire $annee;

    private Programme $programme1;

    private Programme $programme2;

    private Matiere $matiere1;

    private Matiere $matiere2;

    private Group $groupe1;

    private Room $amphiPrincipal;

    private Room $salleMoyenne;

    private Room $salleSansCapacite;

    private SessionExamen $session;

    private Examen $examen;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculte1 = Faculte::create(['nom' => 'Sciences et Technologies', 'code' => 'FST', 'active' => true]);
        $this->faculte2 = Faculte::create(['nom' => 'Lettres et Sciences Humaines', 'code' => 'FLSH', 'active' => true]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Directeur Examens',
            'email' => 'directeur.examens.'.uniqid().'@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->programme1 = Programme::create([
            'faculte_id' => $this->faculte1->id,
            'code' => 'INFO-L',
            'nom' => 'Licence Informatique',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
        ]);
        $niveau1 = Niveau::create(['programme_id' => $this->programme1->id, 'code' => 'L3', 'nom' => 'L3', 'ordre' => 3]);

        $this->programme2 = Programme::create([
            'faculte_id' => $this->faculte2->id,
            'code' => 'LETTRES-L',
            'nom' => 'Licence Lettres',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
        ]);

        $semestre1 = SemestreLmd::create(['niveau_id' => $niveau1->id, 'code' => 'S5', 'nom' => 'S5', 'numero' => 5, 'ordre' => 5]);
        $ue1 = UniteEnseignement::create([
            'semestre_id' => $semestre1->id,
            'programme_id' => $this->programme1->id,
            'code' => 'UE-INFO-301',
            'nom' => 'Informatique Fondamentale',
            'credits' => 12,
            'coefficient' => 4,
        ]);

        $this->matiere1 = Matiere::create([
            'code' => 'INF301',
            'nom' => 'Algorithmique Avancée',
            'ue_id' => $ue1->id,
            'niveau' => 'L3',
            'semestre' => 5,
            'credits' => 6,
            'coefficient' => 2,
            'heures_cm' => 24,
            'heures_td' => 24,
        ]);

        $this->matiere2 = Matiere::create([
            'code' => 'INF302',
            'nom' => 'Génie Logiciel',
            'ue_id' => $ue1->id,
            'niveau' => 'L3',
            'semestre' => 5,
            'credits' => 6,
            'coefficient' => 2,
            'heures_cm' => 24,
            'heures_td' => 24,
        ]);

        $this->groupe1 = Group::create([
            'code' => 'L3-INFO-G1',
            'nom' => 'Groupe L3 Informatique 1',
            'programme_id' => $this->programme1->id,
            'faculte_id' => $this->faculte1->id,
            'niveau' => 'L3',
            'niveau_id' => $niveau1->id,
            'annee_universitaire_id' => $this->annee->id,
            'capacite' => 40,
            'actif' => true,
        ]);

        $batiment = Batiment::create(['faculte_id' => $this->faculte1->id, 'nom' => 'Bâtiment Turing', 'code' => 'BAT-T']);

        // Amphi 100 places nominales -> 50 places utiles à 50%
        $this->amphiPrincipal = Room::create([
            'code' => 'AMPHI-A',
            'nom' => 'Amphithéâtre Alan Turing',
            'faculte_id' => $this->faculte1->id,
            'batiment_id' => $batiment->id,
            'capacite' => 100,
            'actif' => true,
            'type' => 'amphi',
            'heure_ouverture' => '08:00',
            'heure_fermeture' => '19:00',
        ]);

        // Salle 60 places nominales -> 30 places utiles à 50%
        $this->salleMoyenne = Room::create([
            'code' => 'SALLE-101',
            'nom' => 'Salle TD 101',
            'faculte_id' => $this->faculte1->id,
            'batiment_id' => $batiment->id,
            'capacite' => 60,
            'actif' => true,
            'type' => 'td',
            'heure_ouverture' => '08:00',
            'heure_fermeture' => '19:00',
        ]);

        // Salle sans capacité (bloquante selon Décision D-04)
        $this->salleSansCapacite = Room::create([
            'code' => 'SALLE-INVALIDE',
            'nom' => 'Salle Travaux Non Calibrée',
            'faculte_id' => $this->faculte1->id,
            'batiment_id' => $batiment->id,
            'capacite' => null,
            'actif' => true,
            'type' => 'td',
        ]);

        $this->session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS-PLANIF-2027',
            'nom' => 'Session Principale Janvier 2027',
            'type' => 'principale',
            'date_debut' => '2027-01-10',
            'date_fin' => '2027-01-25',
            'statut' => 'open',
        ]);

        $this->examen = Examen::create([
            'annee_universitaire_id' => $this->annee->id,
            'session_id' => $this->session->id,
            'programme_id' => $this->programme1->id,
            'niveau_id' => $niveau1->id,
            'groupe_id' => $this->groupe1->id,
            'code' => 'EX-L3-INFO',
            'libelle' => 'Session Semestre 5 — L3 Info',
            'statut' => 'draft',
        ]);
    }

    public function test_acces_planification_et_salles_refuse_aux_invites(): void
    {
        $this->get(route('admin.evaluation.examens.planification'))->assertRedirect();
        $this->get(route('admin.evaluation.examens.salles'))->assertRedirect();
    }

    public function test_acces_planification_exige_permission_epreuves_view(): void
    {
        $userSansDroit = User::factory()->create();

        $this->actingAs($userSansDroit)
            ->get(route('admin.evaluation.examens.planification'))
            ->assertForbidden();
    }

    public function test_acces_salles_exige_permission_evaluations_view(): void
    {
        $userSansDroit = User::factory()->create();

        $this->actingAs($userSansDroit)
            ->get(route('admin.evaluation.examens.salles'))
            ->assertForbidden();
    }

    public function test_kpis_salles_et_capacites_utiles_50_pourcent(): void
    {
        $service = app(ExamPlanificationSallesOrchestratorService::class);
        $audit = $service->auditerSallesEtCapacites($this->faculte1->id);

        $this->assertEquals(3, $audit['kpis']['total_salles']);
        $this->assertEquals(2, $audit['kpis']['salles_validees']);
        $this->assertEquals(1, $audit['kpis']['salles_bloquantes']);

        // Capacité nominale totale : 100 + 60 = 160
        $this->assertEquals(160, $audit['kpis']['capacite_nominale_totale']);

        // Capacité utile examen à 50% : floor(100*0.5) + floor(60*0.5) = 50 + 30 = 80
        $this->assertEquals(80, $audit['kpis']['capacite_utile_examen_50']);

        // Vérification de la liste des salles bloquantes
        $this->assertCount(1, $audit['salles_bloquantes_liste']);
        $this->assertEquals('SALLE-INVALIDE', $audit['salles_bloquantes_liste'][0]['code']);
    }

    public function test_salle_capacite_nulle_ou_inconnue_est_bloquante(): void
    {
        $service = app(ExamPlanificationSallesOrchestratorService::class);
        $diagInvalide = $service->diagnostiquerSalle($this->salleSansCapacite);

        $this->assertFalse($diagInvalide['est_valide']);
        $this->assertFalse($diagInvalide['opposable']);
        $this->assertEquals(0, $diagInvalide['capacite_utile_50']);
        $this->assertEquals('inconnue', $diagInvalide['statut_code']);

        $diagValide = $service->diagnostiquerSalle($this->amphiPrincipal);
        $this->assertTrue($diagValide['est_valide']);
        $this->assertTrue($diagValide['opposable']);
        $this->assertEquals(50, $diagValide['capacite_utile_50']);
    }

    public function test_generation_et_enrichissement_des_3_scenarios_csp(): void
    {
        // Création de 2 épreuves pour l'examen
        Epreuve::create([
            'annee_universitaire_id' => $this->annee->id,
            'session_id' => $this->session->id,
            'examen_id' => $this->examen->id,
            'code' => 'EP-ALGO',
            'matiere_id' => $this->matiere1->id,
            'groupe_id' => $this->groupe1->id,
            'date' => '2027-01-11',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
            'capacite_necessaire' => 40,
            'statut' => 'draft',
        ]);

        Epreuve::create([
            'annee_universitaire_id' => $this->annee->id,
            'session_id' => $this->session->id,
            'examen_id' => $this->examen->id,
            'code' => 'EP-GENIE',
            'matiere_id' => $this->matiere2->id,
            'groupe_id' => $this->groupe1->id,
            'date' => '2027-01-12',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
            'capacite_necessaire' => 40,
            'statut' => 'draft',
        ]);

        $moteur = app(ExamScheduleEngineService::class);
        $resultatMoteur = $moteur->genererScenariosPlanning($this->examen);

        $this->assertArrayHasKey('scenarios', $resultatMoteur);
        $this->assertArrayHasKey(ExamScheduleEngineService::SCENARIO_ETALE, $resultatMoteur['scenarios']);
        $this->assertArrayHasKey(ExamScheduleEngineService::SCENARIO_COMPACT, $resultatMoteur['scenarios']);
        $this->assertArrayHasKey(ExamScheduleEngineService::SCENARIO_AMPHI, $resultatMoteur['scenarios']);

        // Analyse et enrichissement Enterprise
        $orchestrateur = app(ExamPlanificationSallesOrchestratorService::class);
        $analyse = $orchestrateur->analyserEtEnrichirScenarios($resultatMoteur, $this->examen);

        $this->assertNotNull($analyse['recommandation']);
        $this->assertCount(3, $analyse['resume_comparatif']);

        // Tous les placements doivent être enrichis avec le ratio 50%
        foreach ($analyse['scenarios'] as $sc) {
            foreach ($sc['placements'] as $p) {
                $this->assertArrayHasKey('capacite_utile_50', $p);
                $this->assertArrayHasKey('taux_remplissage_utile', $p);
                $this->assertArrayHasKey('confort_statut', $p);
            }
        }
    }

    public function test_contrainte_dure_anti_collision_groupe(): void
    {
        $moteur = app(ExamScheduleEngineService::class);

        $epreuve1 = Epreuve::create([
            'annee_universitaire_id' => $this->annee->id,
            'session_id' => $this->session->id,
            'examen_id' => $this->examen->id,
            'code' => 'EP-COLLIS-1',
            'matiere_id' => $this->matiere1->id,
            'groupe_id' => $this->groupe1->id,
            'date' => '2027-01-15',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
            'capacite_necessaire' => 30,
            'statut' => 'draft',
        ]);

        $placementsExistants = [
            [
                'epreuve_id' => 999,
                'groupe_id' => $this->groupe1->id,
                'salle_id' => $this->salleMoyenne->id,
                'date' => '2027-01-15',
                'heure_debut' => '09:30',
                'heure_fin' => '11:30',
            ],
        ];

        // Tentative de placer epreuve1 sur le même créneau pour le même groupe
        $check = $moteur->verifierCreneauValide(
            $epreuve1,
            $this->amphiPrincipal,
            '2027-01-15',
            '09:00',
            '11:00',
            0.50,
            $placementsExistants
        );

        $this->assertFalse($check['valide']);
        $this->assertStringContainsString('Collision groupe', $check['motif']);
    }

    public function test_contrainte_dure_anti_collision_salle(): void
    {
        $moteur = app(ExamScheduleEngineService::class);

        $autreGroupe = Group::create([
            'code' => 'L3-AUTRE',
            'nom' => 'Autre Groupe',
            'programme_id' => $this->programme1->id,
            'faculte_id' => $this->faculte1->id,
            'niveau' => 'L3',
            'annee_universitaire_id' => $this->annee->id,
            'capacite' => 20,
            'actif' => true,
        ]);

        $epreuve2 = Epreuve::create([
            'annee_universitaire_id' => $this->annee->id,
            'session_id' => $this->session->id,
            'examen_id' => $this->examen->id,
            'code' => 'EP-COLLIS-2',
            'matiere_id' => $this->matiere2->id,
            'groupe_id' => $autreGroupe->id,
            'date' => '2027-01-15',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
            'capacite_necessaire' => 20,
            'statut' => 'draft',
        ]);

        $placementsExistants = [
            [
                'epreuve_id' => 999,
                'groupe_id' => $this->groupe1->id,
                'salle_id' => $this->amphiPrincipal->id,
                'date' => '2027-01-15',
                'heure_debut' => '10:00',
                'heure_fin' => '12:00',
            ],
        ];

        // Amphi déjà occupé entre 10h et 12h
        $check = $moteur->verifierCreneauValide(
            $epreuve2,
            $this->amphiPrincipal,
            '2027-01-15',
            '09:00',
            '11:00',
            0.50,
            $placementsExistants
        );

        $this->assertFalse($check['valide']);
        $this->assertStringContainsString('Collision salle', $check['motif']);
    }

    public function test_contrainte_dure_capacite_salle_depassee_avec_ratio_50(): void
    {
        $moteur = app(ExamScheduleEngineService::class);

        // Épreuve nécessitant 40 candidats
        $epreuveLourde = Epreuve::create([
            'annee_universitaire_id' => $this->annee->id,
            'session_id' => $this->session->id,
            'examen_id' => $this->examen->id,
            'code' => 'EP-LOURDE',
            'matiere_id' => $this->matiere1->id,
            'groupe_id' => $this->groupe1->id,
            'date' => '2027-01-16',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
            'capacite_necessaire' => 40,
            'statut' => 'draft',
        ]);

        // La salle moyenne a une capacité nominale de 60, mais utile à 50% de 30 places.
        // 40 candidats > 30 places utiles -> rejet strict !
        $check = $moteur->verifierCreneauValide(
            $epreuveLourde,
            $this->salleMoyenne,
            '2027-01-16',
            '09:00',
            '11:00',
            0.50,
            []
        );

        $this->assertFalse($check['valide']);
        $this->assertStringContainsString('Capacité utile insuffisante', $check['motif']);
    }

    public function test_isolation_facultaire_salles_et_planification(): void
    {
        // Création d'une salle dans la faculté 2
        $salleFac2 = Room::create([
            'code' => 'SALLE-FLSH-01',
            'nom' => 'Salle Lettres Modernes',
            'faculte_id' => $this->faculte2->id,
            'capacite' => 50,
            'actif' => true,
        ]);

        $adminFac1 = User::creerCompteBackOffice([
            'name' => 'Admin Sciences',
            'email' => 'admin.sciences.'.uniqid().'@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculte1->id,
        ], 'admin_faculte');
        $adminFac1->update(['faculte_id' => $this->faculte1->id]);

        $responseSalles = $this->actingAs($adminFac1)
            ->get(route('admin.evaluation.examens.salles'));

        $responseSalles->assertOk();
        $responseSalles->assertSee('AMPHI-A');
        $responseSalles->assertDontSee('SALLE-FLSH-01');

        $responsePlanif = $this->actingAs($adminFac1)
            ->get(route('admin.evaluation.examens.planification'));

        $responsePlanif->assertOk();
        $responsePlanif->assertSee('Planification automatique');
    }

    public function test_garantie_zero_ecriture_en_consultation_planification_et_salles(): void
    {
        $compteEpreuvesAvant = Epreuve::count();
        $compteExamensAvant = Examen::count();
        $compteSallesAvant = Room::count();

        $this->actingAs($this->admin)->get(route('admin.evaluation.examens.salles'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.evaluation.examens.planification', ['examen_id' => $this->examen->id]))->assertOk();

        $this->assertEquals($compteEpreuvesAvant, Epreuve::count());
        $this->assertEquals($compteExamensAvant, Examen::count());
        $this->assertEquals($compteSallesAvant, Room::count());
    }
}
