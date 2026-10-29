<?php

namespace Tests\Feature\AssistantIa;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\AssistantIa\Exceptions\SecuriteIaException;
use App\Modules\AssistantIa\Services\RegistreOutils;
use App\Modules\AssistantIa\Tools\Commun\ConsulterActionsDisponiblesTool;
use App\Modules\AssistantIa\Tools\Metier\ConsulterEligibiliteExamenTool;
use App\Modules\AssistantIa\Tools\Metier\ConsulterProduitDataMeshTool;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\ServiceEligibiliteExamen;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3 : Tests de consultation métier en lecture seule.
 * Couvre l'éligibilité aux examens, le Data Mesh, la matrice des actions,
 * l'isolation BOLA/IDOR, l'absence de mutation et le respect des habilitations.
 */
class ConsultationMetierLectureSeuleTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Niveau $niveauL1;

    private SemestreLmd $semestreS1;

    private UniteEnseignement $ue;

    private Matiere $matiere;

    private SessionExamen $sessionPrincipale;

    private SessionExamen $sessionRattrapage;

    private Examen $examen;

    private Epreuve $epreuve;

    private Epreuve $epreuveRattrapage;

    private User $superAdmin;

    private User $agentScolariteFaculteA;

    private User $etudiantUser;

    private Etudiant $etudiantA;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculteA = Faculte::create([
            'nom' => 'Faculté des Sciences de Tunis',
            'code' => 'FST',
            'description' => 'Faculté A de test',
            'active' => true,
        ]);

        $this->faculteB = Faculte::create([
            'nom' => 'Faculté de Droit de Tunis',
            'code' => 'FDT',
            'description' => 'Faculté B de test',
            'active' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        // Structure académique LMD requise
        $this->programme = Programme::create([
            'faculte_id' => $this->faculteA->id,
            'code' => 'LIC-INFO',
            'nom' => 'Licence Sciences Informatiques',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
            'statut' => Programme::STATUT_ACTIF,
            'actif' => true,
        ]);

        $this->niveauL1 = Niveau::create([
            'programme_id' => $this->programme->id,
            'code' => 'L1',
            'nom' => 'Première Année Licence',
            'ordre' => 1,
            'actif' => true,
        ]);

        $this->semestreS1 = SemestreLmd::create([
            'niveau_id' => $this->niveauL1->id,
            'code' => 'S1',
            'nom' => 'Semestre 1',
            'numero' => 1,
            'ordre' => 1,
            'actif' => true,
        ]);

        $this->ue = UniteEnseignement::create([
            'semestre_id' => $this->semestreS1->id,
            'programme_id' => $this->programme->id,
            'code' => 'UE-INF101',
            'nom' => 'Fondements Informatiques',
            'credits' => 6,
            'coefficient' => 1,
            'actif' => true,
        ]);

        $this->matiere = Matiere::create([
            'code' => 'ALGO01',
            'nom' => 'Algorithmique & Structures de Données',
            'niveau' => 'L1',
            'semestre' => 1,
            'credits' => 4,
            'actif' => true,
        ]);

        $this->sessionPrincipale = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS-PRINCIPALE',
            'nom' => 'Session Principale 2027',
            'type' => 'principale',
            'date_debut' => '2027-01-10',
            'date_fin' => '2027-01-20',
            'statut' => 'open',
        ]);

        $this->sessionRattrapage = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'session_origine_id' => $this->sessionPrincipale->id,
            'code' => 'SESS-RATTRAPAGE',
            'nom' => 'Session Rattrapage 2027',
            'type' => 'rattrapage',
            'date_debut' => '2027-02-10',
            'date_fin' => '2027-02-20',
            'statut' => 'open',
        ]);

        $this->examen = Examen::create([
            'session_id' => $this->sessionPrincipale->id,
            'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examen Semestre 1',
            'type' => 'examen',
            'statut' => 'draft',
        ]);

        $this->epreuve = Epreuve::create([
            'examen_id' => $this->examen->id,
            'session_id' => $this->sessionPrincipale->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->matiere->id,
            'ue_id' => $this->ue->id,
            'date' => '2027-01-15',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
            'statut' => 'draft',
        ]);

        $this->epreuveRattrapage = Epreuve::create([
            'examen_id' => $this->examen->id,
            'session_id' => $this->sessionRattrapage->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->matiere->id,
            'ue_id' => $this->ue->id,
            'date' => '2027-02-15',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
            'statut' => 'draft',
        ]);

        // 1. Super Administrateur
        $this->superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'actif' => true,
        ]);

        // 2. Administrateur de Faculté A (restreint à faculteA)
        $this->agentScolariteFaculteA = User::factory()->create([
            'role' => 'admin_faculte',
            'faculte_id' => $this->faculteA->id,
            'actif' => true,
        ]);

        // 3. Étudiant A rattaché à Faculté A
        $this->etudiantA = Etudiant::create([
            'cin' => '12345678',
            'nom' => 'Ben Ali',
            'prenom' => 'Sami',
            'email' => 'sami.benali@test.tn',
            'date_naissance' => '2004-05-10',
            'sexe' => 'M',
            'faculte_id' => $this->faculteA->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Avenue Habib Bourguiba',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => 2024,
            'password' => 'Pass@123',
        ]);
        $this->etudiantA->definirStatut('valide');

        // Compte utilisateur lié à l'étudiant A
        $this->etudiantUser = User::factory()->create([
            'role' => 'etudiant',
            'email' => 'sami.benali@test.tn',
            'actif' => true,
        ]);
        $this->etudiantA->update(['user_id' => $this->etudiantUser->id]);
    }

    public function test_01_eligibilite_examen_nominal_eligible(): void
    {
        // Inscription académique valide + pédagogique à la matière
        $inscription = $this->etudiantA->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1',
            'statut' => 'valide',
        ]);

        $inscription->pedagogiques()->create([
            'semestre_id' => $this->semestreS1->id,
            'ue_id' => $this->ue->id,
            'matiere_id' => $this->matiere->id,
            'statut' => 'inscrit',
        ]);

        $tool = app(ConsulterEligibiliteExamenTool::class);
        $resultat = $tool->executer([
            'epreuve_id' => $this->epreuve->id,
            'etudiant_id' => $this->etudiantA->id,
        ], $this->superAdmin);

        $this->assertTrue($resultat->succes);
        $this->assertSame('eligible', $resultat->donnees['decision']);
        $this->assertTrue($resultat->donnees['est_eligible']);
        $this->assertSame('ALGO01', $resultat->donnees['matiere_code']);
        $this->assertSame(ServiceEligibiliteExamen::REGLE_INSCRIPTION_MODULE_ACTIVE, $resultat->donnees['regle_appliquee']);
        $this->assertArrayNotHasKey('cin', $resultat->donnees);
        $this->assertArrayNotHasKey('email', $resultat->donnees);
    }

    public function test_02_eligibilite_examen_nominal_non_eligible(): void
    {
        // Inscription académique valide MAIS sans inscription pédagogique
        $this->etudiantA->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1',
            'statut' => 'valide',
        ]);

        $tool = app(ConsulterEligibiliteExamenTool::class);
        $resultat = $tool->executer([
            'epreuve_id' => $this->epreuve->id,
            'etudiant_id' => $this->etudiantA->id,
        ], $this->superAdmin);

        $this->assertTrue($resultat->succes);
        $this->assertSame('non_eligible', $resultat->donnees['decision']);
        $this->assertFalse($resultat->donnees['est_eligible']);
        $this->assertSame(ServiceEligibiliteExamen::REGLE_INSCRIPTION_MODULE_ACTIVE, $resultat->donnees['regle_appliquee']);
        $this->assertStringContainsString('pédagogique', (string) $resultat->donnees['motif']);
    }

    public function test_03_eligibilite_examen_session_rattrapage_retourne_anomalie(): void
    {
        // Inscription académique et pédagogique complètes
        $inscription = $this->etudiantA->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1',
            'statut' => 'valide',
        ]);
        $inscription->pedagogiques()->create([
            'semestre_id' => $this->semestreS1->id,
            'ue_id' => $this->ue->id,
            'matiere_id' => $this->matiere->id,
            'statut' => 'inscrit',
        ]);

        $tool = app(ConsulterEligibiliteExamenTool::class);
        $resultat = $tool->executer([
            'epreuve_id' => $this->epreuveRattrapage->id,
            'etudiant_id' => $this->etudiantA->id,
        ], $this->superAdmin);

        $this->assertTrue($resultat->succes);
        $this->assertSame('anomalie', $resultat->donnees['decision']);
        $this->assertSame(ServiceEligibiliteExamen::REGLE_RATTRAPAGE_OFFICIELLE, $resultat->donnees['regle_appliquee']);
        $this->assertStringContainsString('rattrapage', strtolower((string) $resultat->donnees['motif']));
    }

    public function test_04_eligibilite_examen_rejet_bola_hors_faculte(): void
    {
        // Étudiant B rattaché à Faculté B
        $etudiantB = Etudiant::create([
            'cin' => '87654321',
            'nom' => 'Gharbi',
            'prenom' => 'Nadia',
            'email' => 'nadia.gharbi@test.tn',
            'date_naissance' => '2004-03-20',
            'sexe' => 'F',
            'faculte_id' => $this->faculteB->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Rue de Tunis',
            'ville' => 'Tunis',
            'code_postal' => '1002',
            'annee_bac' => 2024,
            'password' => 'Pass@123',
        ]);
        $etudiantB->definirStatut('valide');

        $tool = app(ConsulterEligibiliteExamenTool::class);

        // L'agent Faculté A tente de consulter l'éligibilité de l'étudiant de Faculté B
        $this->expectException(SecuriteIaException::class);
        $this->expectExceptionMessage("Accès refusé : la ressource demandée n'appartient pas à votre faculté de rattachement.");

        $tool->executer([
            'epreuve_id' => $this->epreuve->id,
            'etudiant_id' => $etudiantB->id,
        ], $this->agentScolariteFaculteA);
    }

    public function test_05_eligibilite_examen_rejet_idor_etudiant_croise(): void
    {
        // Étudiant C rattaché à la même faculté mais avec un compte différent
        $etudiantC = Etudiant::create([
            'cin' => '55554444',
            'nom' => 'Mansour',
            'prenom' => 'Leila',
            'email' => 'leila.mansour@test.tn',
            'date_naissance' => '2004-09-15',
            'sexe' => 'F',
            'faculte_id' => $this->faculteA->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Rue El Manar',
            'ville' => 'Tunis',
            'code_postal' => '2092',
            'annee_bac' => 2024,
            'password' => 'Pass@123',
        ]);
        $etudiantC->definirStatut('valide');

        $tool = app(ConsulterEligibiliteExamenTool::class);

        // L'étudiant A tente de consulter le dossier de l'étudiant C
        $this->expectException(SecuriteIaException::class);
        $this->expectExceptionMessage("Accès refusé : vous n'êtes pas autorisé à consulter les données d'éligibilité d'un autre étudiant.");

        $tool->executer([
            'epreuve_id' => $this->epreuve->id,
            'etudiant_id' => $etudiantC->id,
        ], $this->etudiantUser);
    }

    public function test_06_eligibilite_examen_autorise_etudiant_pour_son_propre_compte(): void
    {
        $inscription = $this->etudiantA->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1',
            'statut' => 'valide',
        ]);
        $inscription->pedagogiques()->create([
            'semestre_id' => $this->semestreS1->id,
            'ue_id' => $this->ue->id,
            'matiere_id' => $this->matiere->id,
            'statut' => 'inscrit',
        ]);

        $tool = app(ConsulterEligibiliteExamenTool::class);

        // L'étudiant A consulte son propre compte
        $resultat = $tool->executer([
            'epreuve_id' => $this->epreuve->id,
            'etudiant_id' => $this->etudiantA->id,
        ], $this->etudiantUser);

        $this->assertTrue($resultat->succes);
        $this->assertSame('eligible', $resultat->donnees['decision']);
    }

    public function test_07_data_mesh_mode_decrire_renvoie_schema_sans_donnees_etudiants(): void
    {
        $tool = app(ConsulterProduitDataMeshTool::class);
        $resultat = $tool->executer([
            'code_produit' => 'catalogue_academique',
            'action' => 'decrire',
        ], $this->superAdmin);

        $this->assertTrue($resultat->succes);
        $this->assertSame('catalogue_academique', $resultat->donnees['code']);
        $this->assertSame('referentiel', $resultat->donnees['domaine']);
        $this->assertIsArray($resultat->donnees['schema_champs']);
        $this->assertGreaterThan(0, count($resultat->donnees['schema_champs']));
        $this->assertArrayNotHasKey('echantillon_donnees', $resultat->donnees);
    }

    public function test_08_data_mesh_mode_echantillon_limite_a_5_et_masque_champs_sensibles(): void
    {
        // Créer 7 inscriptions validées pour tester le bornage à 5 et le masquage PII
        for ($i = 1; $i <= 7; $i++) {
            $etu = Etudiant::create([
                'cin' => sprintf('112233%02d', $i),
                'nom' => "Nom{$i}",
                'prenom' => "Prenom{$i}",
                'email' => "etudiant{$i}@faculte.tn",
                'date_naissance' => '2004-01-01',
                'sexe' => 'M',
                'faculte_id' => $this->faculteA->id,
                'niveau' => 'L1',
                'annee_academique' => '2026-2027',
                'adresse' => 'Rue Test',
                'ville' => 'Tunis',
                'code_postal' => '1000',
                'annee_bac' => 2024,
                'password' => 'Pass@123',
            ]);
            $etu->definirStatut('valide');
            $etu->inscriptionsAcademiques()->create([
                'annee_universitaire_id' => $this->annee->id,
                'programme_id' => $this->programme->id,
                'niveau' => 'L1',
                'statut' => 'valide',
            ]);
        }

        $tool = app(ConsulterProduitDataMeshTool::class);
        $resultat = $tool->executer([
            'code_produit' => 'inscriptions_validees',
            'action' => 'echantillon',
        ], $this->superAdmin);

        $this->assertTrue($resultat->succes);
        $this->assertArrayHasKey('echantillon_donnees', $resultat->donnees);

        $inscriptions = $resultat->donnees['echantillon_donnees']['inscriptions'] ?? [];
        $this->assertLessThanOrEqual(5, count($inscriptions));
        $this->assertGreaterThan(0, count($inscriptions));

        // Vérifier le masquage des champs sensibles identifiés
        $premiereLigne = $inscriptions[0];
        $this->assertSame('[MASQUÉ]', $premiereLigne['etudiant']['nom_complet']);
        $this->assertSame('[MASQUÉ]', $premiereLigne['etudiant']['cin_masquee']);
        $this->assertSame('[MASQUÉ]', $premiereLigne['etudiant']['email_masque']);
    }

    public function test_09_data_mesh_produit_inconnu_renvoie_erreur_controlee(): void
    {
        $tool = app(ConsulterProduitDataMeshTool::class);
        $resultat = $tool->executer([
            'code_produit' => 'produit_inexistant_xyz',
            'action' => 'decrire',
        ], $this->superAdmin);

        $this->assertFalse($resultat->succes);
        $this->assertStringContainsString('introuvable ou non référencé', $resultat->message);
        $this->assertStringContainsString('catalogue_academique', $resultat->message);
    }

    public function test_10_actions_disponibles_deduit_strictement_du_serveur_et_ignore_input_client(): void
    {
        $tool = app(ConsulterActionsDisponiblesTool::class);

        // Tentative d'injection ou usurpation de rôle côté client
        $resultatEtudiant = $tool->executer([
            'role' => 'super_admin',
            'permissions' => ['all'],
            'faculte_id' => null,
        ], $this->etudiantUser);

        $this->assertTrue($resultatEtudiant->succes);
        $this->assertSame('etudiant', $resultatEtudiant->donnees['profil']['role']);
        $this->assertFalse($resultatEtudiant->donnees['profil']['est_super_admin']);
        // Un étudiant n'a pas accès aux actions de Niveau 3
        $this->assertSame([], $resultatEtudiant->donnees['niveaux_actions']['niveau_3_actions_sensibles_4_yeux']);

        // Vérification pour un profil Super Admin
        $resultatAdmin = $tool->executer([], $this->superAdmin);
        $this->assertTrue($resultatAdmin->succes);
        $this->assertSame('super_admin', $resultatAdmin->donnees['profil']['role']);
        $this->assertTrue($resultatAdmin->donnees['profil']['est_super_admin']);
        $this->assertGreaterThan(0, count($resultatAdmin->donnees['niveaux_actions']['niveau_3_actions_sensibles_4_yeux']));
    }

    public function test_11_consultation_metier_refuse_utilisateur_sans_permission(): void
    {
        $utilisateurSansDroits = User::factory()->create([
            'role' => 'consultation',
            'actif' => true,
        ]);

        $toolEligibilite = app(ConsulterEligibiliteExamenTool::class);

        $this->expectException(SecuriteIaException::class);
        $toolEligibilite->executer([
            'epreuve_id' => $this->epreuve->id,
            'etudiant_id' => $this->etudiantA->id,
        ], $utilisateurSansDroits);
    }

    public function test_12_preuve_absence_mutation_metier(): void
    {
        $nbEtudiantsAvant = Etudiant::count();
        $nbEpreuvesAvant = Epreuve::count();
        $nbExamensAvant = Examen::count();
        $nbSessionsAvant = SessionExamen::count();
        $nbInscriptionsAvant = InscriptionAcademique::count();
        $nbUsersAvant = User::count();

        $toolEligibilite = app(ConsulterEligibiliteExamenTool::class);
        $toolDataMesh = app(ConsulterProduitDataMeshTool::class);
        $toolActions = app(ConsulterActionsDisponiblesTool::class);

        // Exécutions multiples
        $toolEligibilite->executer([
            'epreuve_id' => $this->epreuve->id,
            'etudiant_id' => $this->etudiantA->id,
        ], $this->superAdmin);

        $toolDataMesh->executer([
            'code_produit' => 'catalogue_academique',
            'action' => 'decrire',
        ], $this->superAdmin);

        $toolActions->executer([], $this->superAdmin);

        // Preuve absolue d'invariance de base de données (0 mutation métier)
        $this->assertSame($nbEtudiantsAvant, Etudiant::count());
        $this->assertSame($nbEpreuvesAvant, Epreuve::count());
        $this->assertSame($nbExamensAvant, Examen::count());
        $this->assertSame($nbSessionsAvant, SessionExamen::count());
        $this->assertSame($nbInscriptionsAvant, InscriptionAcademique::count());
        $this->assertSame($nbUsersAvant, User::count());
    }

    public function test_13_outils_enregistres_dans_registre_officiel_assistant_ia(): void
    {
        $registre = app(RegistreOutils::class);

        $this->assertTrue($registre->existe('consulter_eligibilite_examen'));
        $this->assertTrue($registre->existe('consulter_produit_data_mesh'));
        $this->assertTrue($registre->existe('consulter_actions_disponibles'));

        $outilEligibilite = $registre->obtenir('consulter_eligibilite_examen');
        $this->assertSame('metier', $outilEligibilite->mode());
        $this->assertFalse($outilEligibilite->estSensible());

        $outilDataMesh = $registre->obtenir('consulter_produit_data_mesh');
        $this->assertSame('metier', $outilDataMesh->mode());

        $outilActions = $registre->obtenir('consulter_actions_disponibles');
        $this->assertSame('commun', $outilActions->mode());
    }
}
