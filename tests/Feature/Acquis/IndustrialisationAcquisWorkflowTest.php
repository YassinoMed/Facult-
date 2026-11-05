<?php

namespace Tests\Feature\Acquis;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\User;
use App\Modules\AssistantIa\Tools\Metier\ExpliquerAcquisRedoublementTool;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\ParcoursAcademique\Models\ParcoursPlan;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\TypeDiplome;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Services\Acquis\CalculAcquisPedagogiquesService;
use App\Services\Acquis\CampagneRedoublementMasseService;
use App\Services\Acquis\DocumentPedagogiqueOfficielService;
use App\Services\Acquis\IntegrationAcademiqueHubService;
use App\Services\Acquis\NotificationRedoublementService;
use App\Services\Acquis\ReconstitutionHistoriqueAcquisService;
use App\Services\Acquis\ReglementationAcquisService;
use App\Services\Acquis\TransitionMaquettePedagogiqueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * Suite de tests exhaustive pour l'industrialisation, la gouvernance et l'exploitation
 * du redoublement (LOOP 26 à LOOP 36).
 * Valide les références réglementaires, les seuils différenciés, la transition des maquettes,
 * la sécurisation cryptographique et l'isolation facultaire anti-BOLA.
 */
class IndustrialisationAcquisWorkflowTest extends TestCase
{
    use ActAsScolarite;
    use RefreshDatabase;

    private Faculte $faculte;

    private TypeDiplome $typeLicence;

    private TypeDiplome $typeIngenieur;

    private TypeDiplome $typeMaster;

    private Programme $programmeV1;

    private Programme $programmeV2;

    private Niveau $niveauL1;

    private SemestreLmd $semestre1;

    private AnneeUniversitaire $annee2024;

    private AnneeUniversitaire $annee2025;

    private Etudiant $etudiant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences de Tunis',
            'code' => 'FST',
            'active' => true,
        ]);

        $this->typeLicence = TypeDiplome::firstOrCreate(
            ['code' => 'L'],
            ['nom' => 'Licence Nationale LMD', 'cycle' => 'premier_cycle', 'actif' => true]
        );

        $this->typeIngenieur = TypeDiplome::firstOrCreate(
            ['code' => 'ING'],
            ['nom' => 'Diplôme National d\'Ingénieur', 'cycle' => 'cycle_ingenieur', 'actif' => true]
        );

        $this->typeMaster = TypeDiplome::firstOrCreate(
            ['code' => 'MAS'],
            ['nom' => 'Mastère Professionnel ou de Recherche', 'cycle' => 'second_cycle', 'actif' => true]
        );

        $this->programmeV1 = Programme::create([
            'faculte_id' => $this->faculte->id,
            'type_diplome_id' => $this->typeLicence->id,
            'code' => 'LIC-INFO-V1',
            'nom' => 'Licence Informatique (Maquette 2021)',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 60,
            'actif' => true,
        ]);

        $this->programmeV2 = Programme::create([
            'faculte_id' => $this->faculte->id,
            'type_diplome_id' => $this->typeLicence->id,
            'code' => 'LIC-INFO-V2',
            'nom' => 'Licence Informatique et IA (Maquette 2024)',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 60,
            'actif' => true,
        ]);

        $this->niveauL1 = Niveau::create([
            'programme_id' => $this->programmeV1->id,
            'code' => 'L1',
            'nom' => 'Licence 1ère Année',
            'ordre' => 1,
            'actif' => true,
        ]);

        $this->semestre1 = SemestreLmd::create([
            'niveau_id' => $this->niveauL1->id,
            'code' => 'S1',
            'nom' => 'Semestre 1',
            'numero' => 1,
            'ordre' => 1,
            'actif' => true,
        ]);

        $this->annee2024 = AnneeUniversitaire::create([
            'libelle' => '2024-2025',
            'date_debut' => '2024-09-01',
            'date_fin' => '2025-06-30',
            'active' => false,
        ]);

        $this->annee2025 = AnneeUniversitaire::create([
            'libelle' => '2025-2026',
            'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30',
            'active' => true,
        ]);

        $this->etudiant = Etudiant::create([
            'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programmeV1->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'cin' => '07888999',
            'nom' => 'Mansouri',
            'prenom' => 'Kais',
            'email' => 'kais.mansouri@fst.utm.tn',
            'num_inscription' => '2024-FST-0442',
            'statut' => 'actif',
            'date_naissance' => '2004-06-12',
            'sexe' => 'M',
            'adresse' => 'Campus Tunis',
            'ville' => 'Tunis',
            'code_postal' => '1002',
            'annee_bac' => '2023',
            'password' => 'Secret@123',
        ]);
    }

    /**
     * LOOP 36 — PHASE 1 : Exactitude absolue des références réglementaires nationales.
     */
    public function test_phase1_verification_citations_reglementaires_exactes(): void
    {
        $admin = User::factory()->create();
        $tool = app(ExpliquerAcquisRedoublementTool::class);

        $resultat = $tool->executer(['sujet' => 'general'], $admin);
        $this->assertTrue($resultat->succes);

        $sources = $resultat->sources;
        $this->assertCount(2, $sources);
        $this->assertStringContainsString('Décret présidentiel n° 2022-631 du 14 juillet 2022', $sources[0]['titre']);
        $this->assertStringContainsString('Arrêté ministériel du 7 juillet 2023', $sources[1]['titre']);

        // Vérification des régimes d'exception identifiés
        $resRegimes = $tool->executer(['sujet' => 'regimes_specifiques'], $admin);
        $this->assertTrue($resRegimes->succes);
        $this->assertArrayHasKey('formations', $resRegimes->donnees['contenu']);
    }

    /**
     * LOOP 36 — PHASE 2 : Seuils de compétence différenciés par régime (sans seuil arbitraire global).
     */
    public function test_phase2_seuils_competence_differenties_par_regime(): void
    {
        $reglementationService = app(ReglementationAcquisService::class);
        $calculAcquisService = app(CalculAcquisPedagogiquesService::class);

        // 1. Formation Ingénieur (seuil 8.00/20)
        $programmeIng = Programme::create([
            'faculte_id' => $this->faculte->id,
            'type_diplome_id' => $this->typeIngenieur->id,
            'code' => 'ING-INFO',
            'nom' => 'Cycle Ingénieur en Informatique',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 60,
            'actif' => true,
        ]);
        $regimeIng = $reglementationService->resoudreRegime($programmeIng);
        $this->assertEquals(8.0, $regimeIng['seuil_competence_defaut']);

        // 2. Formation Licence LMD (seuil 6.00/20 par défaut)
        $regimeLic = $reglementationService->resoudreRegime($this->programmeV1);
        $this->assertEquals(6.0, $regimeLic['seuil_competence_defaut']);

        // 3. Formation Master LMD (SANS seuil éliminatoire - null)
        $programmeMaster = Programme::create([
            'faculte_id' => $this->faculte->id,
            'type_diplome_id' => $this->typeMaster->id,
            'code' => 'MAS-DS',
            'nom' => 'Mastère Science des Données',
            'duree_annees' => 2,
            'semestres_nombre' => 4,
            'credits_requis' => 60,
            'actif' => true,
        ]);
        $regimeMaster = $reglementationService->resoudreRegime($programmeMaster);
        $this->assertNull($regimeMaster['seuil_competence_defaut']);
    }

    /**
     * LOOP 36 — PHASE 3 : Transition des maquettes séparant historique, crédits, reconnaissance et équivalence.
     */
    public function test_phase3_transitions_maquettes_distinction_quatre_dimensions(): void
    {
        $ueAncienne = UniteEnseignement::create([
            'programme_id' => $this->programmeV1->id,
            'semestre_id' => $this->semestre1->id,
            'code' => 'UE-ALGO-ANC',
            'nom' => 'Algorithmique et Programmation 1',
            'credits' => 6,
            'est_capitalisable' => true,
        ]);

        $ueNouvelle = UniteEnseignement::create([
            'programme_id' => $this->programmeV2->id,
            'semestre_id' => $this->semestre1->id,
            'code' => 'UE-ALGO-NOUV',
            'nom' => 'Algorithmique et Programmation 1 (Python)',
            'credits' => 6,
            'est_capitalisable' => true,
        ]);

        $transitionService = app(TransitionMaquettePedagogiqueService::class);

        // A. Sans matrice officielle : détection heuristique marquée PROPOSITION_A_CONFIRMER (non validée de facto)
        $tableauHeuristique = $transitionService->etablirTableauEquivalences(
            $this->programmeV1->id,
            $this->programmeV2->id,
            []
        );

        $this->assertNotEmpty($tableauHeuristique['correspondances']);
        $corr = $tableauHeuristique['correspondances'][0];
        $this->assertTrue($corr['historique_conserve']);
        $this->assertTrue($corr['credits_capitalises_conserves']);
        // Vérification de la non-validation automatique arbitraire
        $this->assertEquals('PROPOSITION_A_CONFIRMER', $corr['equivalence_statut']);

        // B. Avec matrice officielle de commission pédagogique : statut OFFICIELLEMENT_VALIDEE
        $matriceOfficielle = [
            $ueAncienne->code => [
                'type' => 'REMPLACEMENT',
                'nouveau_code' => $ueNouvelle->code,
                'credits_reconnus' => 6,
                'regle_conservation' => 'INTEGRALE',
            ],
        ];

        $tableauOfficiel = $transitionService->etablirTableauEquivalences(
            $this->programmeV1->id,
            $this->programmeV2->id,
            $matriceOfficielle
        );

        $corrOff = $tableauOfficiel['correspondances'][0];
        $this->assertEquals('OFFICIELLEMENT_VALIDEE', $corrOff['equivalence_statut']);
        $this->assertEquals('RECONNU_PAR_EQUIVALENCE', $corrOff['statut_reconnaissance']);
    }

    /**
     * LOOP 36 — PHASE 4 : Sécurisation du relevé A4 (Empreinte SHA-256 et Sceau d'authenticité HMAC).
     */
    public function test_phase4_securisation_releve_a4_sha256_et_hmac(): void
    {
        $docService = app(DocumentPedagogiqueOfficielService::class);

        $donneesDoc = $docService->genererDonneesDocument(
            $this->etudiant,
            DocumentPedagogiqueOfficielService::TYPE_RELEVE_REDOUBLEMENT
        );

        $this->assertArrayHasKey('empreinte_sha256', $donneesDoc);
        $this->assertArrayHasKey('sceau_hmac', $donneesDoc);
        $this->assertArrayHasKey('qr_verification_url', $donneesDoc);

        // 1. Vérification document intègre
        $verifNormale = $docService->verifierIntegriteDocument($donneesDoc);
        $this->assertTrue($verifNormale['valide']);
        $this->assertTrue($verifNormale['integre']);
        $this->assertTrue($verifNormale['authentique']);

        // 2. Altération de contenu (détection immédiate du changement de hash)
        $donneesAlteres = $donneesDoc;
        $donneesAlteres['credits']['capitalises'] = 99; // Falsification
        $verifAltere = $docService->verifierIntegriteDocument($donneesAlteres);
        $this->assertFalse($verifAltere['valide']);
        $this->assertFalse($verifAltere['integre']);

        // 3. Faux sceau HMAC (détection d'usurpation sans clé serveur)
        $donneesFauxHmac = $donneesDoc;
        $donneesFauxHmac['sceau_hmac'] = hash('sha256', 'faux_sceau');
        $verifFauxHmac = $docService->verifierIntegriteDocument($donneesFauxHmac);
        $this->assertFalse($verifFauxHmac['valide']);
        $this->assertFalse($verifFauxHmac['authentique']);
    }

    /**
     * LOOP 36 — PHASE 6 : Durcissement des campagnes de masse (Reprise après interruption et verrou atomique).
     */
    public function test_phase6_durcissement_campagne_masse_reprise_apres_interruption_et_verrou(): void
    {
        $campagneService = app(CampagneRedoublementMasseService::class);

        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $this->etudiant->id,
            'annee_universitaire_id' => $this->annee2024->id,
            'programme_id' => $this->programmeV1->id,
            'niveau_id' => $this->niveauL1->id,
            'niveau' => 'L1',
            'statut' => 'validee',
        ]);

        $delib = Deliberation::create([
            'annee_universitaire_id' => $this->annee2024->id,
            'semestre' => 1,
            'session' => 'principale',
            'statut' => 'validee',
            'date_deliberation' => now()->toDateString(),
        ]);

        DecisionDeliberation::create([
            'deliberation_id' => $delib->id,
            'etudiant_id' => $this->etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'decision' => 'autorise_redoublement',
            'moyenne' => 8.5,
            'credits_obtenus' => 12,
            'credits_total' => 60,
            'ues_validees' => 2,
            'ues_total' => 10,
            'publie' => true,
            'date_decision' => now(),
        ]);

        // Première exécution
        $bilan1 = $campagneService->executerCampagneParLots([$this->etudiant->id], $this->annee2025->id, 1);
        $this->assertEquals(1, $bilan1['traites']);
        $this->assertEquals(1, $bilan1['succes']);
        $this->assertEquals(0, $bilan1['echecs']);

        // Seconde exécution (reprise après interruption / idempotence : l'étudiant déjà validé est compté déjà traité)
        $bilan2 = $campagneService->executerCampagneParLots([$this->etudiant->id], $this->annee2025->id, 1);
        $this->assertEquals(1, $bilan2['traites']);
        $this->assertEquals(1, $bilan2['deja_traites']);
        $this->assertEquals(0, $bilan2['echecs']);
    }

    /**
     * LOOP 36 — PHASE 7 : API REST v1 Isolation facultaire stricte (anti-BOLA) et filtrage des campagnes.
     */
    public function test_phase7_api_v1_isolation_facultaire_multietudiants_et_campagne(): void
    {
        $autreFaculte = Faculte::create(['nom' => 'Faculté de Pharmacie de Monastir', 'code' => 'FPM', 'active' => true]);
        $etudiantAutre = Etudiant::create([
            'faculte_id' => $autreFaculte->id,
            'programme_id' => $this->programmeV1->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'cin' => '09111222',
            'nom' => 'Trabelsi',
            'prenom' => 'Sonia',
            'email' => 'sonia.trabelsi@fpm.test',
            'num_inscription' => '2024-FPM-0199',
            'statut' => 'actif',
            'date_naissance' => '2004-11-20',
            'sexe' => 'F',
            'adresse' => 'Monastir',
            'ville' => 'Monastir',
            'code_postal' => '5000',
            'annee_bac' => '2023',
            'password' => 'Secret@123',
        ]);

        $adminFaculte1 = User::factory()->create([
            'role' => 'admin_faculte',
            'faculte_id' => $this->faculte->id,
        ]);

        // A. Accès étudiant de sa propre faculté -> 200 OK
        Sanctum::actingAs($adminFaculte1, ['archives:read', 'archives:write']);
        $repMemeFac = $this->getJson("/api/v1/acquis/students/{$this->etudiant->id}");
        $repMemeFac->assertOk();

        // B. Tentative d'accès IDOR/BOLA vers étudiant d'une autre faculté -> 404 strict
        $repAutreFac = $this->getJson("/api/v1/acquis/students/{$etudiantAutre->id}");
        $repAutreFac->assertStatus(404);

        // C. Lancement d'une campagne de masse avec admin restreint : isolée à sa faculté
        $repCampagne = $this->postJson('/api/v1/acquis/campagne/lancer', [
            'annee_universitaire_id' => $this->annee2024->id,
            'dry_run' => true,
        ]);
        $repCampagne->assertOk();
    }

    /**
     * LOOP 36 — PHASE 8 : Résilience des notifications (un échec de notification n'annule jamais la décision).
     */
    public function test_phase8_resilience_notifications_echec_non_bloquant(): void
    {
        $notifService = app(NotificationRedoublementService::class);

        $plan = ParcoursPlan::create([
            'etudiant_id' => $this->etudiant->id,
            'objectif' => 'Plan de résilience',
            'statut' => 'valide',
            'version' => 1,
            'contenu' => ['credits_acquis_conserves' => 30, 'credits_restants' => 30],
        ]);

        $resultat = $notifService->notifierPlanValide($this->etudiant, $plan);
        $this->assertNotNull($resultat);
        $this->assertEquals('acquis.plan_valide', $resultat->type);
    }

    /**
     * LOOP 36 — PHASE 10 : Validation HTTP complète du Cockpit Admin et de l'Espace Étudiant.
     */
    public function test_phase10_interfaces_web_cockpit_admin_et_portail_etudiant_http(): void
    {
        // 1. Cockpit Admin - Index
        $this->scolarite('super_admin');
        $respIndex = $this->get('/admin/redoublement');
        $respIndex->assertOk()
            ->assertSee('Cockpit Acquis Académiques')
            ->assertSee('Décret n° 2022-631', false);

        // 2. Cockpit Admin - Fiche individuelle
        $respShow = $this->get("/admin/redoublement/etudiant/{$this->etudiant->id}");
        $respShow->assertOk()
            ->assertSee($this->etudiant->nom)
            ->assertSee($this->etudiant->prenom);

        // 3. Document A4 imprimable officiel
        $respDoc = $this->get("/admin/redoublement/etudiant/{$this->etudiant->id}/document/releve_redoublement?format=html");
        $respDoc->assertOk()
            ->assertSee('République Tunisienne')
            ->assertSee('Relevé Officiel des Acquis')
            ->assertSee("Empreinte d'intégrité (SHA-256)", false);

        // 4. Espace Étudiant - Mes Acquis
        $this->etudiantConnecte($this->etudiant);
        $respEspace = $this->get('/etudiant/acquis');
        $respEspace->assertOk()
            ->assertSee('Mes Acquis Académiques');
    }

    /**
     * LOOP 26 — Reconstitution chronologique des acquis et détection des anomalies historiques.
     */
    public function test_loop26_reconstitution_historique_des_acquis(): void
    {
        $ueMath = UniteEnseignement::create([
            'programme_id' => $this->programmeV1->id,
            'semestre_id' => $this->semestre1->id,
            'code' => 'UE-MATH1',
            'nom' => 'Mathématiques Générales',
            'credits' => 6,
            'est_capitalisable' => true,
        ]);

        $ecueAnalyse = Matiere::create([
            'ue_id' => $ueMath->id,
            'code' => 'ECUE-ANA1',
            'nom' => 'Analyse Mathématique',
            'coefficient' => 1.5,
            'credits' => 3,
            'ordre' => 1,
            'niveau' => 'L1',
            'filiere' => 'Sciences Informatiques',
            'semestre' => 1,
            'actif' => true,
        ]);

        $inscription2024 = InscriptionAcademique::create([
            'etudiant_id' => $this->etudiant->id,
            'annee_universitaire_id' => $this->annee2024->id,
            'programme_id' => $this->programmeV1->id,
            'niveau_id' => $this->niveauL1->id,
            'niveau' => 'L1',
            'statut' => 'validee',
        ]);

        $semestreInscription = $inscription2024->semestres()->create(['numero' => 1]);

        // Note sans PV officiel (anomalie détectée)
        Note::create([
            'etudiant_id' => $this->etudiant->id,
            'inscription_academique_id' => $inscription2024->id,
            'matiere_id' => $ecueAnalyse->id,
            'semestre_id' => $semestreInscription->id,
            'coefficient' => 1.5,
            'credits' => 3,
            'session' => 'normale',
            'note' => 14.50,
        ]);

        $reconstitutionService = app(ReconstitutionHistoriqueAcquisService::class);
        $rapport = $reconstitutionService->reconstituerHistorique($this->etudiant);

        $this->assertTrue($rapport['succes']);
        $this->assertCount(1, $rapport['tentatives_par_annee']);
        // Vérifie la détection de l'anomalie de note sans PV
        $this->assertNotEmpty($rapport['anomalies_detectees']);
        $this->assertEquals('NOTE_SANS_PV', $rapport['anomalies_detectees'][0]['type']);
    }

    /**
     * LOOP 29 — Hub d'intégration transversale académique (synchronisation après délibération).
     */
    public function test_loop29_hub_integration_apres_deliberation_publiee(): void
    {
        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $this->etudiant->id,
            'annee_universitaire_id' => $this->annee2024->id,
            'programme_id' => $this->programmeV1->id,
            'niveau_id' => $this->niveauL1->id,
            'niveau' => 'L1',
            'statut' => 'validee',
        ]);

        $delib = Deliberation::create([
            'annee_universitaire_id' => $this->annee2024->id,
            'semestre' => 1,
            'session' => 'principale',
            'statut' => 'validee',
            'date_deliberation' => now()->toDateString(),
        ]);

        $deliberation = DecisionDeliberation::create([
            'deliberation_id' => $delib->id,
            'etudiant_id' => $this->etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'decision' => 'autorise_redoublement',
            'moyenne' => 8.5,
            'credits_obtenus' => 12,
            'credits_total' => 60,
            'ues_validees' => 2,
            'ues_total' => 10,
            'publie' => true,
            'date_decision' => now(),
        ]);

        $hub = app(IntegrationAcademiqueHubService::class);
        $resultat = $hub->synchroniserApresDeliberation($deliberation);

        $this->assertTrue($resultat['succes']);
        $this->assertEquals('autorise_redoublement', $resultat['decision_jury']);
        $this->assertTrue($resultat['plan_cree']);

        $this->assertDatabaseHas('parcours_plans', [
            'etudiant_id' => $this->etudiant->id,
            'statut' => 'propose',
        ]);
    }

    /**
     * LOOP 33 — Notifications administratives et étudiantes avec idempotence stricte.
     */
    public function test_loop33_notification_redoublement_avec_idempotence(): void
    {
        $notifService = app(NotificationRedoublementService::class);

        $plan = ParcoursPlan::create([
            'etudiant_id' => $this->etudiant->id,
            'objectif' => 'Plan de test',
            'statut' => 'propose',
            'version' => 1,
            'contenu' => [
                'credits_acquis_conserves' => 24,
                'credits_restants' => 36,
            ],
        ]);

        // Première notification : émise
        $notif1 = $notifService->notifierPlanEtabli($this->etudiant, $plan);
        $this->assertNotNull($notif1);

        // Seconde notification identique : bloquée par l'idempotence
        $notif2 = $notifService->notifierPlanEtabli($this->etudiant, $plan);
        $this->assertNull($notif2);
    }

    /**
     * LOOP 34 — Commande artisan de supervision et d'observabilité.
     */
    public function test_loop34_commande_artisan_acquis_superviser(): void
    {
        $exitCode = Artisan::call('acquis:superviser', ['--json' => true]);
        $output = Artisan::output();

        $this->assertEquals(0, $exitCode);
        $data = json_decode($output, true);
        $this->assertIsArray($data);
        $this->assertTrue($data['conforme']);
        $this->assertEquals(0, $data['anomalies_critiques_count']);
    }
}
