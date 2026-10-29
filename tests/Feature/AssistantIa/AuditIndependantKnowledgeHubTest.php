<?php

namespace Tests\Feature\AssistantIa;

use App\Models\Faculte;
use App\Models\User;
use App\Modules\AssistantIa\DTO\DemandeIa;
use App\Modules\AssistantIa\DTO\MessageIa;
use App\Modules\AssistantIa\Exceptions\AssistantIaException;
use App\Modules\AssistantIa\Exceptions\SecuriteIaException;
use App\Modules\AssistantIa\Models\AiDocumentIndex;
use App\Modules\AssistantIa\Services\ConnecteurCodeUniRegistre;
use App\Modules\AssistantIa\Services\ConnecteurDocumentationMetier;
use App\Modules\AssistantIa\Services\ConnecteurWebControle;
use App\Modules\AssistantIa\Services\FiltreSecuriteIa;
use App\Modules\AssistantIa\Services\IndexeurDocumentaire;
use App\Modules\AssistantIa\Services\MoteurRechercheDocumentaire;
use App\Modules\AssistantIa\Services\PasserelleFournisseurs;
use App\Modules\AssistantIa\Services\WorkflowApprobationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Suite d'audit indépendant pour le LOOP 15 (Knowledge Hub UniRegistre).
 * Évalue les Lots B, C, D, E et F de façon isolée et reproductible.
 */
class AuditIndependantKnowledgeHubTest extends TestCase
{
    use RefreshDatabase;

    /* =========================================================================
     * LOT B — INDEXATION RÉELLE
     * ========================================================================= */

    public function test_lot_b_cycle_complet_indexation_et_mise_a_jour_incrementale(): void
    {
        $indexeur = new IndexeurDocumentaire([
            'dossiers_autorises' => ['docs/audit_temp'],
            'extensions_autorisees' => ['md'],
            'motifs_strictement_exclus' => ['.env', 'vendor'],
        ]);

        $cheminRelatif = 'docs/audit_temp/reglement_test.md';
        $contenuV1 = "# Titre Règlement\nPremière version de la procédure d'inscription académique.";

        // Étape 1 : Indexation initiale
        $nbMorceauxV1 = $indexeur->indexerContenu($cheminRelatif, 'Règlement Test', $contenuV1, 'docs/audit_temp');
        $this->assertEquals(1, $nbMorceauxV1);

        $docV1 = AiDocumentIndex::where('chemin_fichier', $cheminRelatif)->first();
        $this->assertNotNull($docV1);
        $this->assertEquals(hash('sha256', $contenuV1), $docV1->empreinte_sha256);

        // Étape 2 : Ré-indexation sans modification (doit être idempotente)
        $nbMorceauxIdem = $indexeur->indexerContenu($cheminRelatif, 'Règlement Test', $contenuV1, 'docs/audit_temp');
        $this->assertEquals(1, $nbMorceauxIdem);
        $this->assertEquals(1, AiDocumentIndex::where('chemin_fichier', $cheminRelatif)->count());

        // Étape 3 : Modification du contenu et mise à jour incrémentale
        $contenuV2 = "# Titre Règlement Modifié\nDeuxième version avec mise à jour des conditions LMD.";
        $nbMorceauxV2 = $indexeur->indexerContenu($cheminRelatif, 'Règlement Test Modifié', $contenuV2, 'docs/audit_temp');
        $this->assertEquals(1, $nbMorceauxV2);

        $docV2 = AiDocumentIndex::where('chemin_fichier', $cheminRelatif)->first();
        $this->assertNotNull($docV2);
        $this->assertEquals(hash('sha256', $contenuV2), $docV2->empreinte_sha256);
        $this->assertNotEquals($docV1->empreinte_sha256, $docV2->empreinte_sha256);

        // Étape 4 : Suppression / purge
        AiDocumentIndex::where('chemin_fichier', $cheminRelatif)->forceDelete();
        $this->assertEquals(0, AiDocumentIndex::where('chemin_fichier', $cheminRelatif)->count());
    }

    public function test_lot_b_rejet_fichiers_interdits_et_secrets(): void
    {
        $connecteur = app(ConnecteurCodeUniRegistre::class);

        // Chemins strictement exclus
        $this->assertFalse($connecteur->estFichierCodeAutorise('.env'));
        $this->assertFalse($connecteur->estFichierCodeAutorise('.env.production'));
        $this->assertFalse($connecteur->estFichierCodeAutorise('config/secrets.key'));
        $this->assertFalse($connecteur->estFichierCodeAutorise('storage/app/exports_etudiants.csv'));
        $this->assertFalse($connecteur->estFichierCodeAutorise('vendor/laravel/framework/composer.json'));
        $this->assertFalse($connecteur->estFichierCodeAutorise('../../etc/passwd'));

        // Détection de secrets
        $codeAvecClePrivee = "-----BEGIN RSA PRIVATE KEY-----\nMIIEowIBAAKCAQEA...\n-----END RSA PRIVATE KEY-----";
        $this->assertTrue($connecteur->contientSecrets($codeAvecClePrivee));

        $codeAvecToken = "\$apiKey = 'sk-proj-abcdef12345678901234567890';";
        $this->assertTrue($connecteur->contientSecrets($codeAvecToken));

        $codeAvecAppKey = 'APP_KEY=base64:9999999999999999999999999999999999999999999=';
        $this->assertTrue($connecteur->contientSecrets($codeAvecAppKey));
    }

    public function test_lot_b_filtrage_documents_obsoletes_et_brouillons(): void
    {
        $connecteurDoc = app(ConnecteurDocumentationMetier::class);

        $mdObsolete = "---\ntitre: Ancienne Circulaire\nstatut: obsolete\ndomaine: inscriptions\n---\nTexte archivé.";
        $metaObsolete = $connecteurDoc->analyserMetadonnees($mdObsolete, 'docs/circulaires/2019.md');

        $this->assertEquals('obsolete', $metaObsolete['statut']);
        $this->assertEquals('inscriptions', $metaObsolete['domaine']);

        $mdBrouillon = "---\ntitre: Proposition Maquette\nstatut: brouillon\ndomaine: lmd\n---\nDocument préparatoire.";
        $metaBrouillon = $connecteurDoc->analyserMetadonnees($mdBrouillon, 'docs/propositions/lmd.md');

        $this->assertEquals('brouillon', $metaBrouillon['statut']);
        $this->assertEquals('lmd', $metaBrouillon['domaine']);
    }

    /* =========================================================================
     * LOT C — RECHERCHE ET CITATIONS
     * ========================================================================= */

    public function test_lot_c_recherche_hybride_distinction_exacte_et_semantique(): void
    {
        // Fragment A avec symbole exact
        AiDocumentIndex::create([
            'source' => 'project_code',
            'chemin_fichier' => 'app/Services/InscriptionAcademiqueService.php',
            'titre' => 'InscriptionAcademiqueService',
            'index_morceau' => 0,
            'contenu_morceau' => "Fichier: app/Services/InscriptionAcademiqueService.php\nClasses: InscriptionAcademiqueService\nMethodes: validerDossier, verifierPrerequis\nLogique métier d'inscription.",
            'empreinte_sha256' => hash('sha256', 'codeA'),
            'statut_document' => 'valide',
        ]);

        // Fragment B texte généraliste
        AiDocumentIndex::create([
            'source' => 'internal_document',
            'chemin_fichier' => 'docs/general.md',
            'titre' => 'General',
            'index_morceau' => 0,
            'contenu_morceau' => "Information générale sur les dossiers d'inscription des étudiants.",
            'empreinte_sha256' => hash('sha256', 'docB'),
            'statut_document' => 'valide',
        ]);

        $moteur = app(MoteurRechercheDocumentaire::class);

        // Recherche du symbole exact "validerDossier"
        $resultats = $moteur->rechercher('validerDossier', null, 5);
        $this->assertNotEmpty($resultats);
        $this->assertEquals('app/Services/InscriptionAcademiqueService.php', $resultats[0]['chemin']);
        $this->assertGreaterThan(50, $resultats[0]['score'], 'Le boost de symbole exact doit dépasser 50 points.');
    }

    public function test_lot_c_mesure_latence_recherche_reproductible(): void
    {
        $moteur = app(MoteurRechercheDocumentaire::class);

        // 10 requêtes synthétiques consécutives
        $requetes = [
            'inscription dossier',
            'validerDossier',
            'credits ects',
            'rattrapage examen',
            'faculte mention',
            'ue ecue compensation',
            'deliberation jury',
            'piece manquante cin',
            'workflow approbation',
            'module assistant',
        ];

        $latences = [];
        foreach ($requetes as $req) {
            $t0 = microtime(true);
            $moteur->rechercher($req, null, 3);
            $latences[] = (microtime(true) - $t0) * 1000;
        }

        $min = round(min($latences), 2);
        $max = round(max($latences), 2);
        $avg = round(array_sum($latences) / count($latences), 2);

        $this->assertLessThan(100.0, $avg, "La latence moyenne de recherche ({$avg}ms) doit rester sous les 100ms.");
        $this->assertGreaterThan(0.0, $min);
    }

    public function test_lot_c_isolation_permissions_avant_restitution(): void
    {
        AiDocumentIndex::create([
            'source' => 'internal_document',
            'chemin_fichier' => 'docs/confidentiel.md',
            'titre' => 'Document Confidentiel',
            'index_morceau' => 0,
            'contenu_morceau' => 'Données réservées aux administrateurs de scolarité.',
            'empreinte_sha256' => hash('sha256', 'conf'),
            'statut_document' => 'valide',
            'permission_requise' => 'assistant_ia.sources_restreintes',
        ]);

        $userSansDroit = User::factory()->create();
        $userSansDroit->definirRole('consultation');

        $moteur = app(MoteurRechercheDocumentaire::class);

        // Utilisateur sans la permission
        $resultats = $moteur->rechercher('Confidentiel', $userSansDroit, 5);
        $this->assertCount(0, $resultats);

        // Super administrateur
        $superAdmin = User::factory()->create();
        $superAdmin->role = 'super_admin';
        $superAdmin->save();

        $resultatsAdmin = $moteur->rechercher('Confidentiel', $superAdmin, 5);
        $this->assertCount(1, $resultatsAdmin);
    }

    /* =========================================================================
     * LOT D — CONNECTEUR WEB ET SSRF
     * ========================================================================= */

    public function test_lot_d_connecteur_web_rejet_ssrf_adresses_privees_et_loopback(): void
    {
        $connecteurWeb = app(ConnecteurWebControle::class);

        // IP privées et loopback
        $this->assertFalse($connecteurWeb->estIpPubliqueSecurisee('127.0.0.1'));
        $this->assertFalse($connecteurWeb->estIpPubliqueSecurisee('127.0.1.1'));
        $this->assertFalse($connecteurWeb->estIpPubliqueSecurisee('::1'));
        $this->assertFalse($connecteurWeb->estIpPubliqueSecurisee('10.0.0.5'));
        $this->assertFalse($connecteurWeb->estIpPubliqueSecurisee('192.168.1.100'));
        $this->assertFalse($connecteurWeb->estIpPubliqueSecurisee('172.16.0.1'));
        $this->assertFalse($connecteurWeb->estIpPubliqueSecurisee('169.254.169.254')); // Métadonnées AWS/GCP
        $this->assertFalse($connecteurWeb->estIpPubliqueSecurisee('0.0.0.0'));

        // IP publique valide
        $this->assertTrue($connecteurWeb->estIpPubliqueSecurisee('93.184.216.34')); // example.com
    }

    public function test_lot_d_connecteur_web_rejet_domaines_non_whitelist(): void
    {
        $connecteurWeb = app(ConnecteurWebControle::class);

        $this->assertFalse($connecteurWeb->estDomaineAutorise('evil.attacker.com'));
        $this->assertFalse($connecteurWeb->estDomaineAutorise('google.com'));
        $this->assertFalse($connecteurWeb->estDomaineAutorise('localhost'));
        $this->assertFalse($connecteurWeb->estDomaineAutorise('pastebin.com'));

        // Domaines whitelistés officiels
        $this->assertTrue($connecteurWeb->estDomaineAutorise('laravel.com'));
        $this->assertTrue($connecteurWeb->estDomaineAutorise('php.net'));
        $this->assertTrue($connecteurWeb->estDomaineAutorise('dev.mysql.com'));
        $this->assertTrue($connecteurWeb->estDomaineAutorise('ollama.com'));
        $this->assertTrue($connecteurWeb->estDomaineAutorise('owasp.org'));
    }

    public function test_lot_d_connecteur_web_limite_taille_150ko(): void
    {
        $connecteurWeb = app(ConnecteurWebControle::class);

        // Simulation d'une réponse de 200 Ko (dépasse les 150 Ko max)
        $grosContenu = '<html><head><title>Gros Contenu</title></head><body>'.str_repeat('A', 200 * 1024).'</body></html>';

        Http::fake([
            'https://laravel.com/docs/gros' => Http::response($grosContenu, 200, [
                'Content-Length' => strlen($grosContenu),
                'Content-Type' => 'text/html',
            ]),
        ]);

        $res = $connecteurWeb->consulterPage('https://laravel.com/docs/gros');
        $this->assertTrue($res['succes']);
        // Vérification de la troncature à MAX_OCTETS_REPONSE
        $this->assertLessThanOrEqual(ConnecteurWebControle::MAX_OCTETS_REPONSE, strlen($res['contenu']));
    }

    /* =========================================================================
     * LOT E — APPROBATIONS ET SÉCURITÉ 4 YEUX
     * ========================================================================= */

    public function test_lot_e_tracabilite_et_anti_auto_approbation_4_yeux(): void
    {
        $workflow = app(WorkflowApprobationService::class);

        $demandeur = User::factory()->create();
        $demandeur->definirRole('admin_scolarite');

        $approbateur = User::factory()->create();
        $approbateur->definirRole('admin_scolarite');

        $proposition = $workflow->proposerAction(
            demandeur: $demandeur,
            typeAction: 'valider_inscription',
            ressourceType: 'InscriptionAcademique',
            ressourceId: 101,
            description: 'Validation du dossier 101',
            parametres: ['dossier_id' => 101, 'statut' => 'valide'],
            resumeImpact: 'Validation définitive inscription',
            niveauRisque: 'eleve'
        );

        $this->assertEquals('pending_approval', $proposition->statut);
        $this->assertNotEmpty($proposition->empreinte_sha256);

        // 1. Violation 4 yeux : le demandeur tente d'approuver sa propre proposition
        try {
            $workflow->approuverAction($proposition, $demandeur);
            $this->fail("La règle des 4 yeux aurait dû bloquer l'auto-approbation.");
        } catch (SecuriteIaException $e) {
            $this->assertStringContainsString('4 yeux', $e->getMessage());
        }

        // 2. Approbation par un tiers qualifié
        $resultat = $workflow->approuverAction($proposition, $approbateur, 'Dossier complet conforme');
        $this->assertTrue($resultat['succes']);
        $proposition->refresh();
        $this->assertEquals('completed', $proposition->statut);
        $this->assertEquals($approbateur->id, $proposition->approbateur_id);

        // 3. Protection anti-rejeu : tentative d'exécuter à nouveau une action terminée
        try {
            $workflow->approuverAction($proposition, $approbateur);
            $this->fail('Une action terminée ne doit pas pouvoir être réapprouvée.');
        } catch (SecuriteIaException $e) {
            $this->assertStringContainsString('déjà été traitée', $e->getMessage());
        }
    }

    public function test_lot_e_rejet_apres_alteration_payload_sha256(): void
    {
        $workflow = app(WorkflowApprobationService::class);

        $demandeur = User::factory()->create();
        $demandeur->definirRole('admin_scolarite');

        $approbateur = User::factory()->create();
        $approbateur->definirRole('admin_scolarite');

        $proposition = $workflow->proposerAction(
            demandeur: $demandeur,
            typeAction: 'modifier_note',
            ressourceType: 'Note',
            ressourceId: 50,
            description: 'Rectification note',
            parametres: ['note' => 12.5],
            resumeImpact: 'Rectification de 10 à 12.5',
            niveauRisque: 'critique'
        );

        // Altération directe en base de données du paramètre sans recalcul d'empreinte
        $proposition->parametres = ['note' => 19.5]; // Tentative de fraude
        $proposition->saveQuietly();

        $this->expectException(SecuriteIaException::class);
        $this->expectExceptionMessage("Échec de vérification d'intégrité : le payload de l'action a été altéré.");
        $workflow->approuverAction($proposition, $approbateur);
    }

    public function test_lot_e_rejet_action_expiree(): void
    {
        $workflow = app(WorkflowApprobationService::class);

        $demandeur = User::factory()->create();
        $demandeur->definirRole('admin_scolarite');

        $approbateur = User::factory()->create();
        $approbateur->definirRole('admin_scolarite');

        $proposition = $workflow->proposerAction(
            demandeur: $demandeur,
            typeAction: 'valider_inscription',
            ressourceType: 'InscriptionAcademique',
            ressourceId: 102,
            description: 'Validation',
            parametres: ['dossier_id' => 102],
            resumeImpact: 'Impact',
            niveauRisque: 'moyen'
        );

        // Forcer l'expiration
        $proposition->expire_a = now()->subMinutes(15);
        $proposition->save();

        $this->expectException(SecuriteIaException::class);
        $this->expectExceptionMessage("Cette proposition d'action a expiré");
        $workflow->approuverAction($proposition, $approbateur);
    }

    public function test_lot_e_isolation_facultaire_anti_bola(): void
    {
        $facA = Faculte::create(['nom' => 'Faculté des Sciences', 'code' => 'FS_TEST', 'sigle' => 'FS']);
        $facB = Faculte::create(['nom' => 'Faculté de Droit', 'code' => 'FD_TEST', 'sigle' => 'FD']);

        $demandeur = User::factory()->create(['faculte_id' => $facA->id]);
        $demandeur->definirRole('admin_faculte');

        $approbateurFacB = User::factory()->create(['faculte_id' => $facB->id]);
        $approbateurFacB->definirRole('admin_faculte');

        $this->assertEquals($facB->id, $approbateurFacB->faculteRestreinteId());
        $this->assertFalse($approbateurFacB->peutAccederFaculte($facA->id));
        $this->assertTrue($approbateurFacB->peutAccederFaculte($facB->id));

        // L'approbateur de la Faculté B tente d'accéder à une ressource de la Faculté A
        $this->expectException(SecuriteIaException::class);
        $this->expectExceptionMessage("Accès refusé : la ressource demandée n'appartient pas à votre faculté de rattachement.");
        FiltreSecuriteIa::verifierPerimetreFaculte($approbateurFacB, $facA->id);
    }

    /* =========================================================================
     * LOT F — OLLAMA LOCAL UNIQUEMENT
     * ========================================================================= */

    public function test_lot_f_fournisseur_local_ollama_profil_m2_et_config_locale(): void
    {
        $passerelle = app(PasserelleFournisseurs::class);
        $fournisseurLocal = $passerelle->obtenirFournisseur('local');

        $this->assertEquals('local', $fournisseurLocal->nomFournisseur());

        $diag = $fournisseurLocal->diagnostiquerEnvironnement();

        $this->assertEquals(8, $diag['ram_totale_go']);
        $this->assertEquals('nvidia/NVIDIA-Nemotron-3.5-Bolt-E4B-4GB-DFlash', $diag['modele_recommande']);
        $this->assertEquals('all-minilm', $diag['embeddings_recommandes']);
        $this->assertNotEmpty($diag['instructions_demarrage']);
    }

    public function test_lot_f_fournisseur_local_fail_soft_service_arrete(): void
    {
        $passerelle = app(PasserelleFournisseurs::class);
        $fournisseurLocal = $passerelle->obtenirFournisseur('local');

        // Simulation d'Ollama arrêté (Connection Refused sur port 11434)
        Http::fake([
            'http://127.0.0.1:11434/*' => Http::failedConnection(),
        ]);

        $this->assertFalse($fournisseurLocal->estDisponible());

        $demande = new DemandeIa(
            messages: [MessageIa::utilisateur('Bonjour')],
            promptSysteme: 'Système'
        );

        $this->expectException(AssistantIaException::class);
        $this->expectExceptionMessage("Le service d'inférence locale (Ollama / vLLM) est injoignable");
        $fournisseurLocal->genererReponse($demande);
    }

    public function test_lot_f_absence_bascule_clandestine_cloud(): void
    {
        // Vérification qu'aucune configuration ne bascule silencieusement vers OpenAI en cas d'échec local
        $configFournisseur = config('assistant-ia.fournisseur', 'local');
        $this->assertNotEquals('openai', $configFournisseur);
        $this->assertNotEquals('anthropic', $configFournisseur);
    }
}
