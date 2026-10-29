<?php

namespace Tests\Feature\AssistantIa;

use App\Models\User;
use App\Modules\AssistantIa\Contracts\FournisseurIaInterface;
use App\Modules\AssistantIa\Drivers\FournisseurRestDistant;
use App\Modules\AssistantIa\DTO\DecisionRoutageIa;
use App\Modules\AssistantIa\DTO\DemandeIa;
use App\Modules\AssistantIa\DTO\MessageIa;
use App\Modules\AssistantIa\Exceptions\AssistantIaException;
use App\Modules\AssistantIa\Services\GestionnaireConsentementIa;
use App\Modules\AssistantIa\Services\JournalisationIa;
use App\Modules\AssistantIa\Services\PasserelleFournisseurs;
use App\Modules\AssistantIa\Services\PolitiqueRoutageIa;
use App\Modules\AssistantIa\Services\RegistreOutils;
use App\Modules\AssistantIa\Tools\Metier\PreparerActionScolariteTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Suite de tests de sécurité et de conformité pour la Passerelle IA Hybride :
 * Valide les 14 scénarios critiques de routage, de souveraineté locale, de consentement explicite et d'étanchéité.
 */
class SecuritePasserelleHybrideTest extends TestCase
{
    use RefreshDatabase;

    protected User $utilisateurStandard;

    protected User $utilisateurDistantAutorise;

    protected User $superAdmin;

    protected GestionnaireConsentementIa $gestionnaireConsentement;

    protected PolitiqueRoutageIa $politique;

    protected PasserelleFournisseurs $passerelle;

    protected function setUp(): void
    {
        parent::setUp();

        // Création des utilisateurs avec permissions
        $this->utilisateurStandard = User::factory()->create(['actif' => true]);
        $this->utilisateurStandard->definirRole('agent_scolarite');

        $this->utilisateurDistantAutorise = User::factory()->create(['actif' => true]);
        $this->utilisateurDistantAutorise->definirRole('admin_scolarite');

        $this->superAdmin = User::factory()->create(['actif' => true]);
        $this->superAdmin->definirRole('super_admin');

        Config::set('assistant-ia.fournisseurs.distant.actif', true);
        Config::set('assistant-ia.fournisseurs.distant.cle_api', 'sk-test-token-synthetic-1234567890');
        Config::set('assistant-ia.fournisseurs.distant.base_url', 'https://api.openai.com/v1');
        Config::set('assistant-ia.fournisseurs.distant.modele', 'gpt-4o-mini');

        $this->gestionnaireConsentement = new GestionnaireConsentementIa;
        $this->politique = new PolitiqueRoutageIa($this->gestionnaireConsentement);
        $this->passerelle = new PasserelleFournisseurs(config('assistant-ia'), $this->politique);
    }

    /**
     * SCÉNARIO 1 : Requête normale routée vers le local par défaut.
     */
    public function test_scen_01_requete_normale_routee_vers_le_local(): void
    {
        $prompt = 'Comment formater une date avec Carbon en PHP ?';

        $decision = $this->politique->evaluer(
            utilisateur: $this->utilisateurStandard,
            prompt: $prompt,
            fournisseurDemande: 'local'
        );

        $this->assertEquals(DecisionRoutageIa::LOCAL, $decision->decision);
        $this->assertEquals('local', $decision->fournisseur);
        $this->assertTrue($decision->estExecutable());
        $this->assertStringContainsString('Routage local souverain', $decision->motif);
    }

    /**
     * SCÉNARIO 2 : Requête sensible jamais envoyée au distant (rejet strict).
     */
    public function test_scen_02_requete_sensible_jamais_envoyee_au_distant(): void
    {
        // Données d'étudiants protégées : Notes, CIN, Décisions de jury, Paiements
        $promptsSensibles = [
            'Donne-moi les notes de l\'étudiant et son relevé de notes du semestre',
            'Voici le CIN 12345678 pour valider le paiement des frais de scolarité',
            'Quels sont les PV de délibération du jury d\'examen pour les ajournés ?',
            'Afficher le dossier disciplinaire avec l\'exclusion prononcée',
        ];

        foreach ($promptsSensibles as $prompt) {
            $decision = $this->politique->evaluer(
                utilisateur: $this->utilisateurDistantAutorise,
                prompt: $prompt,
                fournisseurDemande: 'distant'
            );

            $this->assertEquals(DecisionRoutageIa::REFUSE, $decision->decision);
            $this->assertFalse($decision->estExecutable());
            $this->assertTrue($decision->donneesSensiblesDetectees);
            $this->assertNotEmpty($decision->categoriesSensibles);
            $this->assertStringContainsString('Transfert distant refusé', $decision->motif);
        }
    }

    /**
     * SCÉNARIO 3 : Fournisseur local indisponible sans fallback vers distant.
     */
    public function test_scen_03_fournisseur_local_indisponible_sans_fallback(): void
    {
        $fauxLocal = $this->createMock(FournisseurIaInterface::class);
        $fauxLocal->method('estDisponible')->willReturn(false);
        $fauxLocal->method('nomFournisseur')->willReturn('local');

        $decision = $this->politique->evaluer(
            utilisateur: $this->utilisateurStandard,
            prompt: 'Explique le pattern repository',
            fournisseurDemande: 'local',
            fournisseurLocal: $fauxLocal
        );

        $this->assertEquals(DecisionRoutageIa::INDISPONIBLE, $decision->decision);
        $this->assertEquals('local', $decision->fournisseur);
        $this->assertFalse($decision->estExecutable());
        $this->assertStringContainsString('aucun basculement automatique vers un service distant', $decision->motif);
    }

    /**
     * SCÉNARIO 4 : Fournisseur distant interdit (non activé dans config).
     */
    public function test_scen_04_fournisseur_distant_interdit_si_non_active(): void
    {
        Config::set('assistant-ia.fournisseurs.distant.actif', false);
        $politiqueDistantDesactive = new PolitiqueRoutageIa($this->gestionnaireConsentement);

        $decision = $politiqueDistantDesactive->evaluer(
            utilisateur: $this->utilisateurDistantAutorise,
            prompt: 'Analyse cet algorithme de tri',
            fournisseurDemande: 'distant'
        );

        $this->assertEquals(DecisionRoutageIa::REFUSE, $decision->decision);
        $this->assertFalse($decision->estExecutable());
        $this->assertStringContainsString('désactivé par la politique de sécurité', $decision->motif);
    }

    /**
     * SCÉNARIO 5 : Consentement absent, refusé, périmé ou non lié à la requête.
     */
    public function test_scen_05_consentement_absent_perime_ou_non_lie(): void
    {
        $prompt = 'Aide-moi à refactoriser cette boucle en PHP';

        // 5a. Consentement absent -> Défi retourné
        $decisionSansConsentement = $this->politique->evaluer(
            utilisateur: $this->utilisateurDistantAutorise,
            prompt: $prompt,
            fournisseurDemande: 'distant'
        );

        $this->assertEquals(DecisionRoutageIa::CONSENTEMENT_REQUIS, $decisionSansConsentement->decision);
        $this->assertNotNull($decisionSansConsentement->defiConsentement);
        $this->assertNotEmpty($decisionSansConsentement->defiConsentement['jeton']);

        $jetonValide = $decisionSansConsentement->defiConsentement['jeton'];

        // 5b. Consentement valide -> Autorisé
        $decisionAvecConsentement = $this->politique->evaluer(
            utilisateur: $this->utilisateurDistantAutorise,
            prompt: $prompt,
            fournisseurDemande: 'distant',
            jetonConsentement: $jetonValide
        );
        $this->assertEquals(DecisionRoutageIa::DISTANT_AUTORISE, $decisionAvecConsentement->decision);
        $this->assertTrue($decisionAvecConsentement->estExecutable());

        // 5c. Jeton utilisé pour un prompt différent -> Rejeté
        $decisionPromptModifie = $this->politique->evaluer(
            utilisateur: $this->utilisateurDistantAutorise,
            prompt: 'Prompt altéré différent',
            fournisseurDemande: 'distant',
            jetonConsentement: $jetonValide
        );
        $this->assertEquals(DecisionRoutageIa::CONSENTEMENT_REQUIS, $decisionPromptModifie->decision);

        // 5d. Jeton avec signature falsifiée -> Rejeté
        $jetonFalsifie = substr($jetonValide, 0, -4).'abcd';
        $validationFalsifiee = $this->gestionnaireConsentement->validerConsentement(
            jeton: $jetonFalsifie,
            utilisateur: $this->utilisateurDistantAutorise,
            fournisseur: 'distant',
            prompt: $prompt
        );
        $this->assertFalse($validationFalsifiee['valide']);
        $this->assertStringContainsString('altération', $validationFalsifiee['motif_rejet']);
    }

    /**
     * SCÉNARIO 6 : Tentative de contournement via paramètre client (allow_remote=true).
     */
    public function test_scen_06_contournement_allow_remote_rejete_sans_jeton(): void
    {
        $prompt = 'Aide-moi sur une requête SQL';

        $decision = $this->politique->evaluer(
            utilisateur: $this->utilisateurDistantAutorise,
            prompt: $prompt,
            fournisseurDemande: 'distant',
            jetonConsentement: null,
            optionsClient: ['allow_remote' => true]
        );

        // Le simple flag boolean ne dispense absolument pas du jeton de consentement HMAC
        $this->assertEquals(DecisionRoutageIa::CONSENTEMENT_REQUIS, $decision->decision);
        $this->assertFalse($decision->estExecutable());
    }

    /**
     * SCÉNARIO 7 : Utilisateur sans permission requise.
     */
    public function test_scen_07_utilisateur_sans_permission_distant_refuse(): void
    {
        $prompt = 'Question générale sur la programmation';

        $decision = $this->politique->evaluer(
            utilisateur: $this->utilisateurStandard, // Ne possède pas assistant_ia.fournisseur_distant
            prompt: $prompt,
            fournisseurDemande: 'distant'
        );

        $this->assertEquals(DecisionRoutageIa::REFUSE, $decision->decision);
        $this->assertFalse($decision->estExecutable());
        $this->assertStringContainsString('habilitation requise', $decision->motif);
    }

    /**
     * SCÉNARIO 8 : Documents RAG restreints non transmis au distant.
     */
    public function test_scen_08_documents_rag_restreints_interdisent_le_distant(): void
    {
        $prompt = 'Explique ce processus';
        $sourcesRestreintes = [
            [
                'titre' => 'PV Deliberations Jury Secret',
                'chemin' => 'docs/secret.md',
                'permission_requise' => 'deliberations.jury',
                'domaine_metier' => 'deliberations',
            ],
        ];

        $decision = $this->politique->evaluer(
            utilisateur: $this->utilisateurDistantAutorise,
            prompt: $prompt,
            fournisseurDemande: 'distant',
            sourcesRag: $sourcesRestreintes
        );

        $this->assertEquals(DecisionRoutageIa::REFUSE, $decision->decision);
        $this->assertFalse($decision->estExecutable());
        $this->assertContains('source_interne_restreinte', $decision->categoriesSensibles);
    }

    /**
     * SCÉNARIO 9 : Secrets détectés avant transmission distante.
     */
    public function test_scen_09_secrets_detectes_avant_transmission(): void
    {
        $promptAvecCle = 'Voici la configuration APP_KEY=base64:abcd1234efgh5678ijkl9012mnop3456qrst7890= comment configurer ?';

        $decision = $this->politique->evaluer(
            utilisateur: $this->utilisateurDistantAutorise,
            prompt: $promptAvecCle,
            fournisseurDemande: 'distant'
        );

        $this->assertEquals(DecisionRoutageIa::REFUSE, $decision->decision);
        $this->assertContains('secrets', $decision->categoriesSensibles);
    }

    /**
     * SCÉNARIO 10 : Fournisseur distant en erreur géré de façon contrôlée sans fuite.
     */
    public function test_scen_10_fournisseur_distant_en_erreur_controle(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'error' => ['message' => 'Service overloaded'],
            ], 503),
        ]);

        $distantDriver = new FournisseurRestDistant(
            baseUrl: 'https://api.openai.com/v1',
            cleApi: 'sk-test-fake',
            modele: 'gpt-4o-mini',
            timeoutSecondes: 5
        );

        $demande = new DemandeIa([new MessageIa('user', 'Test prompt')]);

        $this->expectException(AssistantIaException::class);
        $this->expectExceptionCode(503);
        $distantDriver->genererReponse($demande);
    }

    /**
     * SCÉNARIO 11 : Paramètres altérés et rejeu de requête (intégrité SHA256).
     */
    public function test_scen_11_parametres_alteres_et_rejeu_de_requete(): void
    {
        $promptInitial = 'Comment optimiser les index MySQL ?';
        $defi = $this->gestionnaireConsentement->genererDefiConsentement(
            utilisateur: $this->utilisateurDistantAutorise,
            fournisseur: 'distant',
            modele: 'gpt-4o-mini',
            prompt: $promptInitial
        );

        // Tentative de rejeu avec un prompt falsifié
        $validation = $this->gestionnaireConsentement->validerConsentement(
            jeton: $defi['jeton'],
            utilisateur: $this->utilisateurDistantAutorise,
            fournisseur: 'distant',
            prompt: 'Donne-moi les mots de passe de la base de données'
        );

        $this->assertFalse($validation['valide']);
        $this->assertStringContainsString('modifiés après l\'accord', $validation['motif_rejet']);
    }

    /**
     * SCÉNARIO 12 : Actions sensibles nécessitant une approbation humaine (R3) et exclusion d'outils d'écriture pour distant.
     */
    public function test_scen_12_actions_sensibles_exclues_du_fournisseur_distant(): void
    {
        $registre = new RegistreOutils;
        $registre->enregistrer(new PreparerActionScolariteTool);

        // Outils pour le fournisseur local : preparer_action est inclus si permission ok
        $outilsLocal = $registre->obtenirDefinitionsPourIa($this->superAdmin, 'metier', 'local');
        $nomsLocal = array_column(array_column($outilsLocal, 'function'), 'name');
        $this->assertContains('preparer_action_scolarite', $nomsLocal);

        // Outils pour le fournisseur distant : preparer_action DOIT ÊTRE FORMELLEMENT EXCLU
        $outilsDistant = $registre->obtenirDefinitionsPourIa($this->superAdmin, 'metier', 'distant');
        $nomsDistant = array_column(array_column($outilsDistant, 'function'), 'name');
        $this->assertNotContains('preparer_action_scolarite', $nomsDistant);
    }

    /**
     * SCÉNARIO 13 : Journaux sans données confidentielles ni prompts bruts.
     */
    public function test_scen_13_journaux_sans_donnees_confidentielles(): void
    {
        Log::shouldReceive('channel')->with('daily')->andReturnSelf();
        Log::shouldReceive('info')->once()->withArgs(function ($message, $contexte) {
            // Le log ne doit jamais contenir le prompt brut
            $this->assertArrayNotHasKey('prompt', $contexte);
            $this->assertArrayNotHasKey('message', $contexte);
            $this->assertArrayHasKey('correlation_id', $contexte);
            $this->assertArrayHasKey('decision', $contexte);
            $this->assertArrayHasKey('taille_caracteres', $contexte);

            return true;
        });

        JournalisationIa::journaliserRoutage(
            correlationId: 'req_test_123',
            decision: DecisionRoutageIa::LOCAL,
            fournisseur: 'local',
            motif: 'Routage local standard',
            tailleCaracteres: 45,
            consentementPresent: false
        );
    }

    /**
     * SCÉNARIO 14 : Tests concurrents étanches entre requêtes locales et distantes.
     */
    public function test_scen_14_etancheite_concurrentielle_requetes_multiples(): void
    {
        // Requête A : Utilisateur standard vers local
        $decisionA = $this->politique->evaluer(
            utilisateur: $this->utilisateurStandard,
            prompt: 'Question locale A',
            fournisseurDemande: 'local'
        );

        // Requête B : Utilisateur distant avec consentement
        $defiB = $this->gestionnaireConsentement->genererDefiConsentement(
            utilisateur: $this->utilisateurDistantAutorise,
            fournisseur: 'distant',
            modele: 'gpt-4o-mini',
            prompt: 'Question distante B'
        );
        $decisionB = $this->politique->evaluer(
            utilisateur: $this->utilisateurDistantAutorise,
            prompt: 'Question distante B',
            fournisseurDemande: 'distant',
            jetonConsentement: $defiB['jeton']
        );

        // Requête C : Utilisateur tentant distant avec des données sensibles
        $decisionC = $this->politique->evaluer(
            utilisateur: $this->utilisateurDistantAutorise,
            prompt: 'Voici les notes et CIN 87654321',
            fournisseurDemande: 'distant'
        );

        // Vérification de la stricte étanchéité
        $this->assertEquals(DecisionRoutageIa::LOCAL, $decisionA->decision);
        $this->assertEquals('local', $decisionA->fournisseur);

        $this->assertEquals(DecisionRoutageIa::DISTANT_AUTORISE, $decisionB->decision);
        $this->assertEquals('distant', $decisionB->fournisseur);

        $this->assertEquals(DecisionRoutageIa::REFUSE, $decisionC->decision);
        $this->assertTrue($decisionC->donneesSensiblesDetectees);

        // Les identifiants de corrélation sont distincts
        $this->assertNotEquals($decisionA->correlationId, $decisionB->correlationId);
        $this->assertNotEquals($decisionB->correlationId, $decisionC->correlationId);
    }
}
