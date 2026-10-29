<?php

namespace Tests\Feature\AssistantIa;

use App\Models\User;
use App\Modules\AssistantIa\Drivers\FournisseurLocal;
use App\Modules\AssistantIa\DTO\DecisionRoutageIa;
use App\Modules\AssistantIa\DTO\DemandeIa;
use App\Modules\AssistantIa\DTO\MessageIa;
use App\Modules\AssistantIa\DTO\ReponseIa;
use App\Modules\AssistantIa\Exceptions\AssistantIaException;
use App\Modules\AssistantIa\Exceptions\SecuriteIaException;
use App\Modules\AssistantIa\Models\AiConversation;
use App\Modules\AssistantIa\Models\AiDocumentIndex;
use App\Modules\AssistantIa\Models\AiSourceConnaissance;
use App\Modules\AssistantIa\Services\MoteurRechercheDocumentaire;
use App\Modules\AssistantIa\Services\PasserelleFournisseurs;
use App\Modules\AssistantIa\Services\PolitiqueRoutageIa;
use App\Modules\AssistantIa\Services\RegistreSourcesConnaissances;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Suite de validation finale du LOOP 18 — UniRegistre Assistant IA.
 * Couvre les vérifications HTTP, les permissions, l'idempotence des sources,
 * la politique souveraine Fail-Soft (zéro fallback cloud) et le mode simulateur.
 */
class ValidationFinaleAssistantIaTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected User $agentScolarite;

    protected User $utilisateurConsultation;

    protected User $utilisateurSansDroit;

    protected User $utilisateurInactif;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create(['role' => 'super_admin', 'actif' => true]);
        $this->agentScolarite = User::factory()->create(['role' => 'agent_scolarite', 'actif' => true]);
        $this->utilisateurConsultation = User::factory()->create(['role' => 'consultation', 'actif' => true]);
        $this->utilisateurSansDroit = User::factory()->create(['role' => 'etudiant', 'actif' => true]);
        $this->utilisateurInactif = User::factory()->create(['role' => 'super_admin', 'actif' => false]);
    }

    // =========================================================================
    // PHASE 1 — ROUTES ET AUTHENTIFICATION HTTP
    // =========================================================================

    public function test_route_noms_et_controleurs_sont_conformes(): void
    {
        $this->assertTrue(Route::has('admin.assistant-ia.index'));
        $this->assertTrue(Route::has('admin.assistant-ia.conversations.index'));
        $this->assertTrue(Route::has('admin.assistant-ia.conversations.creer'));
        $this->assertTrue(Route::has('admin.assistant-ia.conversations.show'));
        $this->assertTrue(Route::has('admin.assistant-ia.messages.envoyer'));
        $this->assertTrue(Route::has('admin.assistant-ia.sources.index'));

        $routeIndex = Route::getRoutes()->getByName('admin.assistant-ia.index');
        $this->assertContains('GET', $routeIndex->methods());

        $routeConversations = Route::getRoutes()->getByName('admin.assistant-ia.conversations.index');
        $this->assertContains('GET', $routeConversations->methods());

        $routeCreer = Route::getRoutes()->getByName('admin.assistant-ia.conversations.creer');
        $this->assertContains('POST', $routeCreer->methods());

        $routeSources = Route::getRoutes()->getByName('admin.assistant-ia.sources.index');
        $this->assertContains('GET', $routeSources->methods());
    }

    public function test_visiteur_non_authentifie_est_redirige_vers_connexion_sur_toutes_les_routes(): void
    {
        $routesGet = [
            route('admin.assistant-ia.index'),
            route('admin.assistant-ia.conversations.index'),
            route('admin.assistant-ia.sources.index'),
            route('admin.assistant-ia.conversations.show', '00000000-0000-0000-0000-000000000000'),
        ];

        foreach ($routesGet as $url) {
            $response = $this->get($url);
            $response->assertRedirect('/admin/connexion');
        }

        $responsePostCreer = $this->post(route('admin.assistant-ia.conversations.creer'), ['titre' => 'Test']);
        $responsePostCreer->assertRedirect('/admin/connexion');

        $responsePostMsg = $this->post(route('admin.assistant-ia.messages.envoyer', '00000000-0000-0000-0000-000000000000'), ['contenu' => 'Bonjour']);
        $responsePostMsg->assertRedirect('/admin/connexion');
    }

    public function test_super_administrateur_accede_avec_succes_a_toutes_les_routes_principales(): void
    {
        // 1. GET /admin/assistant-ia
        $resIndex = $this->actingAs($this->superAdmin)->get(route('admin.assistant-ia.index'));
        $resIndex->assertOk();
        $resIndex->assertViewIs('modules.assistant-ia.index');
        $resIndex->assertSee('UniRegistre AI');

        // 2. GET /admin/assistant-ia/conversations
        $resConversations = $this->actingAs($this->superAdmin)->get(route('admin.assistant-ia.conversations.index'));
        $resConversations->assertOk();
        $resConversations->assertViewIs('modules.assistant-ia.index');

        // 3. GET /admin/assistant-ia/sources
        $resSources = $this->actingAs($this->superAdmin)->get(route('admin.assistant-ia.sources.index'));
        $resSources->assertOk();
        $resSources->assertViewIs('modules.assistant-ia.sources');
        $resSources->assertSee('Knowledge Hub UniRegistre');
    }

    public function test_utilisateur_sans_permission_assistant_ia_est_interdit_avec_code_403(): void
    {
        // Rôle étudiant n'a pas assistant_ia.view
        $response = $this->actingAs($this->utilisateurSansDroit)->get(route('admin.assistant-ia.index'));
        $response->assertForbidden();

        $responseConv = $this->actingAs($this->utilisateurSansDroit)->get(route('admin.assistant-ia.conversations.index'));
        $responseConv->assertForbidden();

        $responseSources = $this->actingAs($this->utilisateurSansDroit)->get(route('admin.assistant-ia.sources.index'));
        $responseSources->assertForbidden();
    }

    public function test_utilisateur_sans_droit_admin_est_interdit_dacces_aux_sources_du_hub(): void
    {
        // L'agent de scolarité a assistant_ia.view, mais pas assistant_ia.admin
        $resIndex = $this->actingAs($this->agentScolarite)->get(route('admin.assistant-ia.index'));
        $resIndex->assertOk();

        $resSources = $this->actingAs($this->agentScolarite)->get(route('admin.assistant-ia.sources.index'));
        $resSources->assertForbidden();

        // Idem pour le rôle consultation
        $resSourcesConsult = $this->actingAs($this->utilisateurConsultation)->get(route('admin.assistant-ia.sources.index'));
        $resSourcesConsult->assertForbidden();
    }

    public function test_utilisateur_inactif_est_bloque_systematiquement_avec_code_403(): void
    {
        $response = $this->actingAs($this->utilisateurInactif)->get(route('admin.assistant-ia.index'));
        $response->assertForbidden();

        $responseConv = $this->actingAs($this->utilisateurInactif)->get(route('admin.assistant-ia.conversations.index'));
        $responseConv->assertForbidden();

        $responseSources = $this->actingAs($this->utilisateurInactif)->get(route('admin.assistant-ia.sources.index'));
        $responseSources->assertForbidden();
    }

    public function test_creation_conversation_isolee_avec_donnees_valides(): void
    {
        $response = $this->actingAs($this->superAdmin)->post(route('admin.assistant-ia.conversations.creer'), [
            'titre' => 'Session Validation LOOP 18',
            'mode' => 'metier',
            'fournisseur' => 'faux',
        ]);

        $conversation = AiConversation::where('titre', 'Session Validation LOOP 18')->first();
        $this->assertNotNull($conversation);
        $this->assertEquals($this->superAdmin->id, $conversation->user_id);
        $this->assertEquals('faux', $conversation->fournisseur);
        $this->assertEquals('metier', $conversation->mode);

        $response->assertRedirect(route('admin.assistant-ia.conversations.show', $conversation->uuid));
    }

    public function test_validation_des_donnees_rejette_les_requetes_invalides(): void
    {
        // 1. Mode invalide lors de la création
        $resModeInvalide = $this->actingAs($this->superAdmin)->post(route('admin.assistant-ia.conversations.creer'), [
            'mode' => 'mode_inexistant_xyz',
        ]);
        $resModeInvalide->assertSessionHasErrors(['mode']);

        // 2. Fournisseur inconnu
        $resFournisseurInvalide = $this->actingAs($this->superAdmin)->post(route('admin.assistant-ia.conversations.creer'), [
            'fournisseur' => 'fournisseur_inconnu_xyz',
        ]);
        $resFournisseurInvalide->assertSessionHasErrors(['fournisseur']);

        // 3. Message vide
        $conv = AiConversation::create([
            'user_id' => $this->superAdmin->id,
            'titre' => 'Test',
            'mode' => 'metier',
            'fournisseur' => 'faux',
            'modele' => 'fake-model-v1',
            'dernier_message_a' => now(),
        ]);

        $resMsgVide = $this->actingAs($this->superAdmin)->post(route('admin.assistant-ia.messages.envoyer', $conv->uuid), [
            'contenu' => '',
        ]);
        $resMsgVide->assertSessionHasErrors(['contenu']);

        // 4. Message trop long (> 4000 caractères)
        $resMsgTropLong = $this->actingAs($this->superAdmin)->post(route('admin.assistant-ia.messages.envoyer', $conv->uuid), [
            'contenu' => str_repeat('A', 4001),
        ]);
        $resMsgTropLong->assertSessionHasErrors(['contenu']);
    }

    public function test_anti_idor_aucune_fuite_de_donnees_entre_utilisateurs_distincts(): void
    {
        $convAdmin = AiConversation::create([
            'user_id' => $this->superAdmin->id,
            'titre' => 'Secret Admin',
            'mode' => 'metier',
            'fournisseur' => 'faux',
            'modele' => 'fake-model-v1',
            'dernier_message_a' => now(),
        ]);

        // L'agent de scolarité ne peut pas afficher la conversation du superAdmin
        $resShow = $this->actingAs($this->agentScolarite)->get(route('admin.assistant-ia.conversations.show', $convAdmin->uuid));
        $resShow->assertNotFound();

        // L'agent ne peut pas poster dans la conversation de l'admin
        $resMsg = $this->actingAs($this->agentScolarite)->post(route('admin.assistant-ia.messages.envoyer', $convAdmin->uuid), [
            'contenu' => 'Tentative injection message',
        ]);
        $resMsg->assertNotFound();

        // L'agent ne peut pas supprimer la conversation de l'admin
        $resDelete = $this->actingAs($this->agentScolarite)->delete(route('admin.assistant-ia.conversations.supprimer', $convAdmin->uuid));
        $resDelete->assertNotFound();
    }

    public function test_envoi_message_et_reponse_modele_dans_conversation_autorisee(): void
    {
        $conv = AiConversation::create([
            'user_id' => $this->superAdmin->id,
            'titre' => 'Discussion Valide',
            'mode' => 'metier',
            'fournisseur' => 'faux',
            'modele' => 'fake-model-v1',
            'dernier_message_a' => now(),
        ]);

        $resJson = $this->actingAs($this->superAdmin)->postJson(route('admin.assistant-ia.messages.envoyer', $conv->uuid), [
            'contenu' => 'Quelles sont les dates de délibération ?',
        ]);

        $resJson->assertOk();
        $resJson->assertJsonPath('succes', true);
        $this->assertDatabaseHas('ai_messages', [
            'conversation_id' => $conv->id,
            'role' => 'user',
            'contenu' => 'Quelles sont les dates de délibération ?',
        ]);
        $this->assertDatabaseHas('ai_messages', [
            'conversation_id' => $conv->id,
            'role' => 'assistant',
        ]);
    }

    // =========================================================================
    // PHASE 2 — MIGRATIONS ET SOURCES DU KNOWLEDGE HUB
    // =========================================================================

    public function test_idempotence_initialisation_sources_fondamentales_sans_doublon(): void
    {
        $registre = app(RegistreSourcesConnaissances::class);

        // Premier appel
        $registre->initialiserSourcesFondamentales();
        $nbInitial = AiSourceConnaissance::count();
        $this->assertEquals(3, $nbInitial);

        // Second appel (doit être idempotent sans créer de nouveaux enregistrements)
        $registre->initialiserSourcesFondamentales();
        $this->assertEquals(3, AiSourceConnaissance::count());

        $this->assertDatabaseHas('ai_sources_connaissances', ['origine' => 'docs', 'statut' => 'actif']);
        $this->assertDatabaseHas('ai_sources_connaissances', ['origine' => 'docs/engineering', 'statut' => 'actif']);
        $this->assertDatabaseHas('ai_sources_connaissances', ['origine' => 'docs/architecture', 'statut' => 'actif']);
    }

    public function test_detection_et_gestion_dune_source_avec_chemin_invalide_ou_inaccessible(): void
    {
        $registre = app(RegistreSourcesConnaissances::class);

        $source = $registre->enregistrerSource(
            nom: 'Dossier Introuvable',
            type: 'internal_document',
            origine: 'repertoire_completement_inexistant_xyz999'
        );

        $disponible = $registre->verifierDisponibilite($source);
        $this->assertFalse($disponible);

        $resultat = $registre->synchroniserSource($source);
        $this->assertFalse($resultat['succes']);
        $this->assertStringContainsString('inaccessible', $resultat['message']);

        $source->refresh();
        $this->assertEquals('erreur', $source->statut);
        $this->assertNotEmpty($source->derniere_erreur);
    }

    public function test_rejet_strict_path_traversal_dans_origine_source(): void
    {
        $this->expectException(SecuriteIaException::class);

        $registre = app(RegistreSourcesConnaissances::class);
        $registre->enregistrerSource(
            nom: 'Attaque Traversal Test',
            type: 'internal_document',
            origine: 'docs/../../etc/passwd'
        );
    }

    // =========================================================================
    // PHASE 3 — POLITIQUE FOURNISSEURS ET SOUVERAINETÉ (FAIL-SOFT)
    // =========================================================================

    public function test_fournisseur_local_indisponible_ne_declenche_jamais_de_fallback_cloud_silencieux(): void
    {
        // Simuler la panne du service Ollama local (ConnectionException)
        Http::fake([
            'http://127.0.0.1:11434/*' => fn () => throw new ConnectionException('Connection refused to 127.0.0.1:11434'),
            'https://api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'Reponse Cloud']]]], 200),
        ]);

        $fournisseurLocal = new FournisseurLocal(
            baseUrl: 'http://127.0.0.1:11434/v1',
            cleApi: 'secret-local',
            modele: 'qwen2.5-coder:7b'
        );

        $demande = new DemandeIa([MessageIa::utilisateur('Vérifier absence fallback cloud')]);

        try {
            $fournisseurLocal->genererReponse($demande);
            $this->fail("Une AssistantIaException 504 aurait dû être levée lors de l'indisponibilité d'Ollama.");
        } catch (AssistantIaException $e) {
            $this->assertEquals(504, $e->getCode());
            $this->assertStringContainsString('injoignable', $e->getMessage());
            $this->assertStringContainsString('Aucun repli distant n\'est effectué', $e->getMessage());
        }

        // Preuve formelle absolue : aucun appel sortant vers le cloud
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'api.openai.com'));
    }

    public function test_fournisseur_distant_desactive_ou_non_autorise_est_refuse(): void
    {
        $politique = new PolitiqueRoutageIa(
            config: [
                'fournisseur' => 'local',
                'fournisseurs' => [
                    'distant' => ['actif' => false],
                    'local' => ['actif' => true],
                ],
                'routage' => [
                    'local_first' => true,
                    'distant_autorise' => false,
                    'permission_distant' => 'assistant_ia.fournisseur_distant',
                ],
            ]
        );

        $decision = $politique->evaluer(
            utilisateur: $this->superAdmin,
            prompt: 'Calculer les statistiques de réussite',
            fournisseurDemande: 'distant'
        );

        $this->assertEquals(DecisionRoutageIa::REFUSE, $decision->decision);
        $this->assertStringContainsString('désactivé', $decision->motif);
    }

    public function test_requete_sensible_est_bloquee_localement_et_jamais_transmise_au_distant(): void
    {
        $politique = new PolitiqueRoutageIa(
            config: [
                'fournisseur' => 'local',
                'fournisseurs' => [
                    'distant' => ['actif' => true],
                    'local' => ['actif' => true],
                ],
                'routage' => [
                    'local_first' => true,
                    'distant_autorise' => true,
                    'permission_distant' => 'assistant_ia.fournisseur_distant',
                ],
            ]
        );

        // Demande contenant une délibération de notes étudiantes
        $decision = $politique->evaluer(
            utilisateur: $this->superAdmin,
            prompt: 'Afficher les notes et délibération de l étudiant INE123456789',
            fournisseurDemande: 'distant'
        );

        // Le transfert distant doit être formellement refusé en présence de notes / PII
        $this->assertEquals(DecisionRoutageIa::REFUSE, $decision->decision);
        $this->assertStringContainsString('données protégées', $decision->motif);
    }

    public function test_consentement_client_seul_est_rejete_sans_signature_hmac_serveur(): void
    {
        $politique = new PolitiqueRoutageIa(
            config: [
                'fournisseur' => 'local',
                'fournisseurs' => [
                    'distant' => ['actif' => true],
                    'local' => ['actif' => true],
                ],
                'routage' => [
                    'local_first' => true,
                    'distant_autorise' => true,
                    'permission_distant' => 'assistant_ia.fournisseur_distant',
                ],
            ]
        );

        // Le client passe allow_remote=true mais aucun jeton HMAC serveur valide
        $decision = $politique->evaluer(
            utilisateur: $this->superAdmin,
            prompt: 'Générer un exemple de code PHP',
            fournisseurDemande: 'distant',
            jetonConsentement: null,
            optionsClient: ['allow_remote' => true]
        );

        $this->assertEquals(DecisionRoutageIa::CONSENTEMENT_REQUIS, $decision->decision);
    }

    public function test_aucune_cle_api_ni_secret_dans_les_reponses_ou_exceptions(): void
    {
        Http::fake([
            'http://127.0.0.1:11434/*' => Http::response('{"error": "Unauthorized key secret-ollama-xyz"}', 401),
        ]);

        $fournisseur = new FournisseurLocal(
            baseUrl: 'http://127.0.0.1:11434/v1',
            cleApi: 'secret-ollama-xyz',
            modele: 'qwen2.5-coder:7b'
        );

        try {
            $fournisseur->genererReponse(new DemandeIa([MessageIa::utilisateur('Test clé')]));
            $this->fail('Une AssistantIaException 401 aurait dû être levée.');
        } catch (AssistantIaException $e) {
            $message = $e->getMessage();
            $this->assertStringNotContainsString('secret-ollama-xyz', $message);
        }
    }

    // =========================================================================
    // PHASE 4 — KNOWLEDGE HUB, RAG ET INTERFACE
    // =========================================================================

    public function test_simulateur_deterministe_explicitement_identifie_et_fonctionnel(): void
    {
        $passerelle = app(PasserelleFournisseurs::class);
        $simulateur = $passerelle->obtenirFournisseur('faux');

        $this->assertEquals('faux', $simulateur->nomFournisseur());
        $this->assertTrue($simulateur->estDisponible());

        $demande = new DemandeIa([MessageIa::utilisateur('Procédure inscription')]);
        $reponse = $simulateur->genererReponse($demande);

        $this->assertInstanceOf(ReponseIa::class, $reponse);
        $this->assertStringContainsString('Écho contrôlé', $reponse->texte);
    }

    public function test_statut_fournisseurs_indique_clairement_indisponibilite_ollama_local(): void
    {
        Http::fake([
            'http://127.0.0.1:11434/*' => Http::response(null, 503),
            'http://localhost:11434/*' => Http::response(null, 503),
        ]);

        // En local de test avec daemon Ollama simulé hors-ligne
        $resStatut = $this->actingAs($this->superAdmin)->get(route('admin.assistant-ia.fournisseurs.statut'));
        $resStatut->assertOk();
        $donnees = $resStatut->json();

        $this->assertArrayHasKey('local', $donnees);
        $this->assertArrayHasKey('disponible', $donnees['local']);
        $this->assertFalse($donnees['local']['disponible'], "Ollama local doit être détecté indisponible lorsque le port 11434 n'est pas ouvert.");
    }

    public function test_rag_sources_reelles_liees_sans_hallucination_de_citations(): void
    {
        AiDocumentIndex::create([
            'source' => 'docs/reglement-scolarite.md',
            'chemin_fichier' => 'docs/reglement-scolarite.md',
            'titre' => 'Règlement Intérieur Scolarité 2026',
            'index_morceau' => 0,
            'contenu_morceau' => 'Les inscriptions administratives se clôturent impérativement le 31 octobre à minuit.',
            'empreinte_sha256' => hash('sha256', 'Les inscriptions administratives...'),
            'statut_document' => 'valide',
        ]);

        $moteurRecherche = app(MoteurRechercheDocumentaire::class);
        $resultats = $moteurRecherche->rechercher('inscriptions administratives clôturent', $this->superAdmin, 3);

        $this->assertNotEmpty($resultats);
        $premier = $resultats[0];
        $this->assertEquals('docs/reglement-scolarite.md', $premier['source']);
        $this->assertEquals('Règlement Intérieur Scolarité 2026', $premier['titre']);
        $this->assertStringContainsString('31 octobre', $premier['extrait']);
    }
}
