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
use App\Modules\Evaluation\Services\ExamDashboardOrchestratorService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suite de tests Enterprise pour le Tableau de bord des examens (Menu 1) — EA-EXAMENS-01.
 *
 * Couvre :
 *  - Authentification et contrôle des permissions
 *  - Cloisonnement facultaire et isolation des données
 *  - Cohérence et calcul consolidé des KPI
 *  - Score de préparation multi-dimensionnel (D1-D7)
 *  - Détection consolidée des conflits (Familles A, B, C, D)
 *  - Diagnostic des capacités de salles et ratio utile 50%
 *  - Workflow de gouvernance à 4 yeux
 *  - Grille d'intégration des 12 menus
 *  - Garantie d'absence totale d'écriture lors de la consultation
 */
class ExamDashboardEnterpriseTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte1;

    private Faculte $faculte2;

    private AnneeUniversitaire $annee;

    private Matiere $matiere1;

    private Room $salle1;

    private Group $groupe1;

    private SessionExamen $sessionInit;

    private Examen $examen;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        // Facultés distinctes pour tester le cloisonnement
        $this->faculte1 = Faculte::create(['nom' => 'Sciences', 'code' => 'FS', 'description' => 'Fac des Sciences', 'active' => true]);
        $this->faculte2 = Faculte::create(['nom' => 'Lettres', 'code' => 'FL', 'description' => 'Fac des Lettres', 'active' => true]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin Examens',
            'email' => 'admin.examens'.uniqid().'@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $programme1 = Programme::create([
            'faculte_id' => $this->faculte1->id,
            'code' => 'INFO',
            'nom' => 'Informatique',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
        ]);
        $niveau1 = Niveau::create(['programme_id' => $programme1->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);
        $semestre1 = SemestreLmd::create(['niveau_id' => $niveau1->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1]);
        $ue1 = UniteEnseignement::create([
            'semestre_id' => $semestre1->id,
            'programme_id' => $programme1->id,
            'code' => 'UE-INFO-101',
            'nom' => 'Algo 1',
            'credits' => 6,
            'coefficient' => 2,
        ]);
        $this->matiere1 = Matiere::create([
            'code' => 'ALGO101',
            'nom' => 'Algorithmique & Structures',
            'ue_id' => $ue1->id,
            'niveau' => 'L1',
            'semestre' => 1,
            'credits' => 6,
            'coefficient' => 2,
        ]);

        $this->groupe1 = Group::create([
            'code' => 'GRP-A',
            'nom' => 'Groupe A',
            'programme_id' => $programme1->id,
            'faculte_id' => $this->faculte1->id,
            'niveau' => 'L1',
            'capacite' => 30,
            'actif' => true,
        ]);

        $batiment = Batiment::create(['code' => 'BAT-CENTRAL', 'nom' => 'Bâtiment Central', 'actif' => true]);

        $this->salle1 = Room::create([
            'code' => 'AMPHI-A',
            'nom' => 'Grand Amphi A',
            'capacite' => 100,
            'batiment_id' => $batiment->id,
            'faculte_id' => $this->faculte1->id,
            'actif' => true,
        ]);

        $this->sessionInit = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS-INIT',
            'nom' => 'Session Initiale',
            'type' => 'principale',
            'date_debut' => '2026-12-01',
            'date_fin' => '2026-12-30',
            'statut' => 'open',
        ]);

        $this->examen = Examen::create([
            'annee_universitaire_id' => $this->annee->id,
            'session_id' => $this->sessionInit->id,
            'code' => 'EX-INFO-01',
            'libelle' => 'Examen Algo',
            'type' => 'examen',
            'statut' => 'draft',
            'programme_id' => $programme1->id,
        ]);
    }

    public function test_acces_non_authentifie_redirige_vers_login(): void
    {
        $response = $this->get(route('admin.evaluation.examens.tableau-de-bord'));

        $response->assertRedirect();
    }

    public function test_acces_utilisateur_sans_permission_renvoie_403(): void
    {
        $userSansDroit = User::factory()->create();

        $response = $this->actingAs($userSansDroit)
            ->get(route('admin.evaluation.examens.tableau-de-bord'));

        $response->assertForbidden();
    }

    public function test_administrateur_peut_acceder_au_tableau_de_bord_enterprise(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.evaluation.examens.tableau-de-bord', ['annee_id' => $this->annee->id]));

        $response->assertOk();
        $response->assertViewIs('modules.evaluation.examens-advanced.tableau-de-bord');
        $response->assertSee('Centre de Commandement des Examens');
        $response->assertSee('Scores de Préparation des Sessions');
        $response->assertSee('Matrice Intelligente des Conflits de Planification');
        $response->assertSee('Gouvernance des Plannings');
        $response->assertSee('Hub de Navigation Contextuelle des 12 Menus');
    }

    public function test_kpis_consolides_et_harmonises_avec_filtre_annee(): void
    {
        // Épreuve rattachée à la session initiale
        $epreuve = Epreuve::create([
            'annee_universitaire_id' => $this->annee->id,
            'examen_id' => $this->examen->id,
            'code' => 'EP-01',
            'session_id' => $this->sessionInit->id,
            'matiere_id' => $this->matiere1->id,
            'groupe_id' => $this->groupe1->id,
            'salle_id' => $this->salle1->id,
            'date' => '2026-12-16',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
            'statut' => 'scheduled',
            'capacite_necessaire' => 25,
        ]);

        $orchestrator = app(ExamDashboardOrchestratorService::class);
        $donnees = $orchestrator->obtenirTableauDeBordEnterprise($this->annee->id, $this->faculte1->id, $this->admin);

        $this->assertEquals(1, $donnees['kpis']['sessions']['total']);
        $this->assertEquals(1, $donnees['kpis']['sessions']['open']);
        $this->assertEquals(1, $donnees['kpis']['epreuves']['total']);
        $this->assertEquals(1, $donnees['kpis']['epreuves']['planifiees']);
        $this->assertEquals(0, $donnees['kpis']['epreuves']['sans_salle']);
        $this->assertNotEmpty($donnees['horodatage']);
    }

    public function test_score_preparation_session_calcule_et_explicable(): void
    {
        $session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS-R1',
            'nom' => 'Session Rattrapage S1',
            'type' => 'rattrapage',
            'date_debut' => '2027-01-10',
            'date_fin' => '2027-01-15',
            'statut' => 'draft',
        ]);

        $orchestrator = app(ExamDashboardOrchestratorService::class);
        $donnees = $orchestrator->obtenirTableauDeBordEnterprise($this->annee->id, $this->faculte1->id, $this->admin);

        $this->assertCount(2, $donnees['scores_sessions']);
        $scoreSession = $donnees['scores_sessions'][0];

        $this->assertArrayHasKey('score_global', $scoreSession);
        $this->assertArrayHasKey('statut_eligibilite', $scoreSession);
        $this->assertArrayHasKey('dimensions', $scoreSession);
        $this->assertArrayHasKey('bloquants', $scoreSession);
        $this->assertArrayHasKey('avertissements', $scoreSession);

        $this->assertGreaterThanOrEqual(0, $scoreSession['score_global']);
        $this->assertLessThanOrEqual(100, $scoreSession['score_global']);

        // Vérifier présence des 7 dimensions D1 à D7
        foreach (['D1', 'D2', 'D3', 'D4', 'D5', 'D6', 'D7'] as $codeDim) {
            $this->assertArrayHasKey($codeDim, $scoreSession['dimensions']);
            $this->assertGreaterThanOrEqual(0, $scoreSession['dimensions'][$codeDim]['score']);
            $this->assertGreaterThan(0, $scoreSession['dimensions'][$codeDim]['max']);
        }
    }

    public function test_detection_conflits_par_familles(): void
    {
        $session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS-CONF',
            'nom' => 'Session Conflits Test',
            'type' => 'principale',
            'date_debut' => '2026-12-10',
            'date_fin' => '2026-12-20',
            'statut' => 'open',
        ]);

        // Conflit Famille D : Heures incohérentes (début > fin)
        $epreuveHoraireIncoherent = Epreuve::create([
            'annee_universitaire_id' => $this->annee->id,
            'examen_id' => $this->examen->id,
            'code' => 'EP-HORAIRE',
            'session_id' => $session->id,
            'matiere_id' => $this->matiere1->id,
            'date' => '2026-12-12',
            'heure_debut' => '14:00',
            'heure_fin' => '10:00', // Incohérent !
            'statut' => 'draft',
        ]);

        // Conflit Famille A : Capacité insuffisante (salle utile = 15 places, effectif requis = 40)
        $petiteSalle = Room::create([
            'code' => 'PETITE-1',
            'nom' => 'Petite Salle',
            'capacite' => 30, // 50% = 15 places utiles
            'faculte_id' => $this->faculte1->id,
            'actif' => true,
        ]);

        $epreuveSurcharge = Epreuve::create([
            'annee_universitaire_id' => $this->annee->id,
            'examen_id' => $this->examen->id,
            'code' => 'EP-SURCHARGE',
            'session_id' => $session->id,
            'matiere_id' => $this->matiere1->id,
            'salle_id' => $petiteSalle->id,
            'date' => '2026-12-14',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
            'statut' => 'scheduled',
            'capacite_necessaire' => 40, // 40 > 15 places
        ]);

        // Conflit Famille D : Hors période de session (session termine le 20/12, épreuve le 25/12)
        $epreuveHorsSession = Epreuve::create([
            'annee_universitaire_id' => $this->annee->id,
            'examen_id' => $this->examen->id,
            'code' => 'EP-HORS-SESS',
            'session_id' => $session->id,
            'matiere_id' => $this->matiere1->id,
            'date' => '2026-12-25', // Hors session
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
            'statut' => 'scheduled',
        ]);

        $orchestrator = app(ExamDashboardOrchestratorService::class);
        $donnees = $orchestrator->obtenirTableauDeBordEnterprise($this->annee->id, $this->faculte1->id, $this->admin);

        $matrice = $donnees['matrice_conflits'];

        $this->assertNotEmpty($matrice['famille_planning']);
        $this->assertNotEmpty($matrice['famille_salles']);
        $this->assertGreaterThan(0, $matrice['totaux']['critiques']);

        // Vérification des règles associées
        $reglesViolees = collect($matrice['liste_complete'])->pluck('regle_violee')->all();
        $this->assertContains('C-HARD-01', $reglesViolees); // Horaire
        $this->assertContains('C-HARD-02', $reglesViolees); // Capacité
        $this->assertContains('C-HARD-06', $reglesViolees); // Période session
    }

    public function test_diagnostic_capacite_salles_applique_ratio_anti_fraude_50_pourcent(): void
    {
        $orchestrator = app(ExamDashboardOrchestratorService::class);
        $donnees = $orchestrator->obtenirTableauDeBordEnterprise($this->annee->id, $this->faculte1->id, $this->admin);

        $diag = $donnees['diagnostic_salles'];

        $this->assertEquals(100, $this->salle1->capacite);
        // Le ratio utile doit être de 50%
        $this->assertEquals(50, $diag['capacite_utile_totale']);
        $this->assertEquals(100, $diag['capacite_nominale_totale']);
    }

    public function test_gouvernance_workflow_4_yeux_et_anti_auto_approbation(): void
    {
        $orchestrator = app(ExamDashboardOrchestratorService::class);
        $donnees = $orchestrator->obtenirTableauDeBordEnterprise($this->annee->id, $this->faculte1->id, $this->admin);

        $gouvernance = $donnees['gouvernance'];

        $this->assertTrue($gouvernance['anti_auto_approbation_active']);
        $this->assertArrayHasKey('statut_general', $gouvernance);
        $this->assertArrayHasKey('propositions_en_cours', $gouvernance);
    }

    public function test_navigation_contextuelle_12_menus_preserve_filtre_annee(): void
    {
        $orchestrator = app(ExamDashboardOrchestratorService::class);
        $donnees = $orchestrator->obtenirTableauDeBordEnterprise($this->annee->id, $this->faculte1->id, $this->admin);

        $grille = $donnees['grille_menus'];

        $this->assertCount(12, $grille);

        // Vérifier la présence de chaque menu de 1 à 12
        for ($i = 1; $i <= 12; $i++) {
            $menu = collect($grille)->firstWhere('numero', $i);
            $this->assertNotNull($menu, "Le menu numéro {$i} doit être présent dans la grille.");
            $this->assertStringContainsString("annee_id={$this->annee->id}", $menu['route']);
        }
    }

    public function test_garantie_zero_ecriture_en_consultation_dashboard(): void
    {
        $nbSessionsAvant = SessionExamen::count();
        $nbEpreuvesAvant = Epreuve::count();
        $nbSallesAvant = Room::count();
        $nbExamensAvant = Examen::count();

        $response = $this->actingAs($this->admin)
            ->get(route('admin.evaluation.examens.tableau-de-bord', ['annee_id' => $this->annee->id]));

        $response->assertOk();

        // Absolument aucune écriture
        $this->assertEquals($nbSessionsAvant, SessionExamen::count());
        $this->assertEquals($nbEpreuvesAvant, Epreuve::count());
        $this->assertEquals($nbSallesAvant, Room::count());
        $this->assertEquals($nbExamensAvant, Examen::count());
    }

    public function test_isolation_facultaire_sans_fuite_de_donnees(): void
    {
        // Créer un utilisateur restreint à la faculté 1
        $userFac1 = User::creerCompteBackOffice([
            'name' => 'Agent Faculte 1',
            'email' => 'agent.fac1'.uniqid().'@universite.test',
            'password' => 'Motdepasse@123',
        ], 'agent_scolarite');
        $userFac1->update(['faculte_id' => $this->faculte1->id]);

        // Session
        $session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS-COM',
            'nom' => 'Session Commune',
            'type' => 'principale',
            'date_debut' => '2026-12-10',
            'date_fin' => '2026-12-20',
            'statut' => 'open',
        ]);

        // Épreuve de la faculté 1
        Epreuve::create([
            'annee_universitaire_id' => $this->annee->id,
            'examen_id' => $this->examen->id,
            'code' => 'EP-FAC1',
            'session_id' => $session->id,
            'matiere_id' => $this->matiere1->id,
            'groupe_id' => $this->groupe1->id, // Rattaché à Faculte 1
            'date' => '2026-12-12',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
            'statut' => 'scheduled',
        ]);

        // Épreuve de la faculté 2
        $programme2 = Programme::create([
            'faculte_id' => $this->faculte2->id,
            'code' => 'LETTRES',
            'nom' => 'Lettres Modernes',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
        ]);
        $groupe2 = Group::create([
            'code' => 'GRP-LETTRES',
            'nom' => 'Groupe Lettres',
            'programme_id' => $programme2->id,
            'faculte_id' => $this->faculte2->id,
            'niveau' => 'L1',
            'capacite' => 20,
            'actif' => true,
        ]);
        $examen2 = Examen::create([
            'annee_universitaire_id' => $this->annee->id,
            'session_id' => $session->id,
            'code' => 'EX-LETTRES-01',
            'libelle' => 'Examen Lettres',
            'type' => 'examen',
            'statut' => 'draft',
            'programme_id' => $programme2->id,
        ]);
        Epreuve::create([
            'annee_universitaire_id' => $this->annee->id,
            'examen_id' => $examen2->id,
            'code' => 'EP-FAC2',
            'session_id' => $session->id,
            'matiere_id' => $this->matiere1->id,
            'groupe_id' => $groupe2->id, // Rattaché à Faculte 2
            'date' => '2026-12-13',
            'heure_debut' => '14:00',
            'heure_fin' => '16:00',
            'statut' => 'scheduled',
        ]);

        $orchestrator = app(ExamDashboardOrchestratorService::class);

        // L'agent de Faculte 1 ne doit voir que 1 épreuve
        $donneesFac1 = $orchestrator->obtenirTableauDeBordEnterprise($this->annee->id, $this->faculte1->id, $userFac1);
        $this->assertEquals(1, $donneesFac1['kpis']['epreuves']['total']);

        // L'agent de Faculte 2 ne doit voir que 1 épreuve
        $donneesFac2 = $orchestrator->obtenirTableauDeBordEnterprise($this->annee->id, $this->faculte2->id, $userFac1);
        $this->assertEquals(1, $donneesFac2['kpis']['epreuves']['total']);

        // L'admin global sans restriction voit les 2 épreuves
        $donneesGlobal = $orchestrator->obtenirTableauDeBordEnterprise($this->annee->id, null, $this->admin);
        $this->assertEquals(2, $donneesGlobal['kpis']['epreuves']['total']);
    }
}
