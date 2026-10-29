<?php

namespace Tests\Feature\AssistantIa;

use App\Modules\AssistantIa\Drivers\FournisseurLocal;
use App\Modules\AssistantIa\DTO\DemandeIa;
use App\Modules\AssistantIa\DTO\MessageIa;
use App\Modules\AssistantIa\Exceptions\AssistantIaException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FournisseurLocalOllamaTest extends TestCase
{
    protected FournisseurLocal $fournisseur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fournisseur = new FournisseurLocal(
            baseUrl: 'http://127.0.0.1:11434/v1',
            cleApi: 'local-token',
            modele: 'nvidia/NVIDIA-Nemotron-3.5-Bolt-E4B-4GB-DFlash',
            modeleEmbeddings: 'all-minilm',
            timeoutSecondes: 10
        );
    }

    public function test_diagnostic_materiel_et_profilage_apple_silicon_m2(): void
    {
        $diag = $this->fournisseur->diagnostiquerEnvironnement();

        $this->assertArrayHasKey('service_joignable', $diag);
        $this->assertArrayHasKey('endpoint', $diag);
        $this->assertArrayHasKey('ram_totale_go', $diag);
        $this->assertArrayHasKey('disque_libre_go', $diag);
        $this->assertArrayHasKey('modele_recommande', $diag);
        $this->assertArrayHasKey('embeddings_recommandes', $diag);
        $this->assertArrayHasKey('instructions_demarrage', $diag);

        $this->assertEquals('http://127.0.0.1:11434/v1', $diag['endpoint']);
        $this->assertGreaterThan(0, $diag['ram_totale_go']);
        $this->assertGreaterThan(0, $diag['disque_libre_go']);

        // Vérification de la recommandation de modèle Nemotron 3.5 Bolt
        $this->assertEquals('nvidia/NVIDIA-Nemotron-3.5-Bolt-E4B-4GB-DFlash', $diag['modele_recommande']);
        $this->assertEquals('all-minilm', $diag['embeddings_recommandes']);
        $this->assertNotEmpty($diag['instructions_demarrage']);
    }

    public function test_lister_modeles_quand_ollama_repond(): void
    {
        Http::fake([
            'http://127.0.0.1:11434/v1/models' => Http::response([
                'data' => [
                    ['id' => 'qwen2.5-coder:7b'],
                    ['id' => 'bge-m3:latest'],
                ],
            ], 200),
        ]);

        $this->assertTrue($this->fournisseur->estDisponible());

        $modeles = $this->fournisseur->listerModelesInstalles();
        $this->assertCount(2, $modeles);
        $this->assertContains('qwen2.5-coder:7b', $modeles);
        $this->assertContains('bge-m3:latest', $modeles);
    }

    public function test_comportement_quand_ollama_est_eteint_ou_inaccessible(): void
    {
        Http::fake([
            'http://127.0.0.1:11434/v1/models' => Http::response(null, 503),
            'http://127.0.0.1:11434/v1/chat/completions' => Http::response(null, 503),
        ]);

        $this->assertFalse($this->fournisseur->estDisponible());
        $this->assertEmpty($this->fournisseur->listerModelesInstalles());

        $demande = new DemandeIa([new MessageIa('user', 'Bonjour')]);

        $this->expectException(AssistantIaException::class);
        $this->expectExceptionMessage('Le moteur d\'inférence local a renvoyé une erreur');
        $this->fournisseur->genererReponse($demande);
    }

    public function test_generation_reponse_locale_avec_appels_outils_et_usage(): void
    {
        Http::fake([
            'http://127.0.0.1:11434/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-local-123',
                'object' => 'chat.completion',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Voici l\'explication du contrôleur d\'inscriptions.',
                            'tool_calls' => [
                                [
                                    'id' => 'call_1',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'inspecter_routes',
                                        'arguments' => '{"prefixe":"admin/assistant-ia"}',
                                    ],
                                ],
                            ],
                        ],
                        'finish_reason' => 'tool_calls',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 120,
                    'completion_tokens' => 45,
                ],
            ], 200),
        ]);

        $demande = new DemandeIa(
            messages: [new MessageIa('user', 'Quelles sont les routes ?')],
            outilsDisponibles: [['type' => 'function', 'function' => ['name' => 'inspecter_routes']]]
        );

        $reponse = $this->fournisseur->genererReponse($demande);

        $this->assertEquals('local', $reponse->fournisseur);
        $this->assertEquals('nvidia/NVIDIA-Nemotron-3.5-Bolt-E4B-4GB-DFlash', $reponse->modele);
        $this->assertEquals('Voici l\'explication du contrôleur d\'inscriptions.', $reponse->texte);
        $this->assertTrue($reponse->aDesAppelsOutils());
        $this->assertCount(1, $reponse->appelsOutils);
        $this->assertEquals('inspecter_routes', $reponse->appelsOutils[0]->nomOutil);
        $this->assertEquals(120, $reponse->tokensPrompt);
        $this->assertEquals(45, $reponse->tokensReponse);
        $this->assertGreaterThan(0.0, $reponse->latenceMs);
    }

    public function test_profil_nemotron_bolt_4gb_et_all_minilm(): void
    {
        $fournisseurNemotron = new FournisseurLocal(
            baseUrl: 'http://127.0.0.1:11434/v1',
            cleApi: 'local-token',
            modele: 'nvidia/NVIDIA-Nemotron-3.5-Bolt-E4B-4GB-DFlash',
            modeleEmbeddings: 'all-minilm',
            timeoutSecondes: 10
        );

        $diag = $fournisseurNemotron->diagnostiquerEnvironnement();

        $this->assertEquals('nvidia/NVIDIA-Nemotron-3.5-Bolt-E4B-4GB-DFlash', $diag['modele_actif']);
        $this->assertEquals('nvidia/NVIDIA-Nemotron-3.5-Bolt-E4B-4GB-DFlash', $diag['modele_nemotron']);
        $this->assertEquals('all-minilm', $diag['embeddings_ultra_legers']);
        $this->assertArrayHasKey('profil_materiel', $diag);
        $this->assertEquals('nvidia/NVIDIA-Nemotron-3.5-Bolt-E4B-4GB-DFlash', $diag['profil_materiel']['modele_recommande_generatif']);
        $this->assertEquals('all-minilm', $diag['profil_materiel']['modele_recommande_embeddings']);
    }

    public function test_smollm_135m_exclut_les_outils_du_payload_nativement(): void
    {
        $fournisseur = new FournisseurLocal(
            baseUrl: 'http://127.0.0.1:11434/v1',
            cleApi: 'local-token',
            modele: 'smollm:135m'
        );

        $requeteCapturee = null;
        Http::fake([
            'http://127.0.0.1:11434/v1/chat/completions' => function ($request) use (&$requeteCapturee) {
                $requeteCapturee = $request->data();

                return Http::response([
                    'id' => 'chatcmpl-smollm',
                    'object' => 'chat.completion',
                    'choices' => [
                        [
                            'index' => 0,
                            'message' => ['role' => 'assistant', 'content' => 'Réponse purement générative.'],
                            'finish_reason' => 'stop',
                        ],
                    ],
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                ], 200);
            },
        ]);

        $demande = new DemandeIa(
            messages: [new MessageIa('user', 'Bonjour')],
            outilsDisponibles: [['type' => 'function', 'function' => ['name' => 'test_outil']]]
        );

        $reponse = $fournisseur->genererReponse($demande);

        $this->assertEquals('Réponse purement générative.', $reponse->texte);
        $this->assertNotNull($requeteCapturee);
        $this->assertArrayNotHasKey('tools', $requeteCapturee, 'Les modèles ultra-légers ≤ 200M ne doivent pas recevoir de tools dans le payload.');
    }

    public function test_repli_automatique_sans_outils_lorsque_ollama_renvoie_http_400_does_not_support_tools(): void
    {
        // Modèle générique qui envoie des outils au départ
        $fournisseur = new FournisseurLocal(
            baseUrl: 'http://127.0.0.1:11434/v1',
            cleApi: 'local-token',
            modele: 'custom-model:latest'
        );

        $nbAppels = 0;
        Http::fake([
            'http://127.0.0.1:11434/v1/chat/completions' => function ($request) use (&$nbAppels) {
                $nbAppels++;
                $data = $request->data();

                if (isset($data['tools'])) {
                    // 1er appel avec tools : rejeté par Ollama avec HTTP 400
                    return Http::response([
                        'error' => [
                            'message' => 'registry.ollama.ai/library/custom-model does not support tools',
                            'type' => 'invalid_request_error',
                        ],
                    ], 400);
                }

                // 2e appel sans tools : accepté avec succès
                return Http::response([
                    'id' => 'chatcmpl-custom',
                    'object' => 'chat.completion',
                    'choices' => [
                        [
                            'index' => 0,
                            'message' => ['role' => 'assistant', 'content' => 'Réponse après repli sans outils.'],
                            'finish_reason' => 'stop',
                        ],
                    ],
                    'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 6],
                ], 200);
            },
        ]);

        $demande = new DemandeIa(
            messages: [new MessageIa('user', 'Test auto-repli')],
            outilsDisponibles: [['type' => 'function', 'function' => ['name' => 'outil_inexploitable']]]
        );

        $reponse = $fournisseur->genererReponse($demande);

        $this->assertEquals(2, $nbAppels, 'Le fournisseur doit avoir réessayé automatiquement sans tools.');
        $this->assertEquals('Réponse après repli sans outils.', $reponse->texte);
        $this->assertFalse($reponse->aDesAppelsOutils());
    }

    public function test_message_erreur_detaille_remonte_lors_echec_ollama(): void
    {
        $fournisseur = new FournisseurLocal(
            baseUrl: 'http://127.0.0.1:11434/v1',
            cleApi: 'local-token',
            modele: 'modele-introuvable'
        );

        Http::fake([
            'http://127.0.0.1:11434/v1/chat/completions' => Http::response([
                'error' => ['message' => "model 'modele-introuvable' not found"],
            ], 404),
        ]);

        $demande = new DemandeIa([new MessageIa('user', 'Bonjour')]);

        $this->expectException(AssistantIaException::class);
        $this->expectExceptionMessage("model 'modele-introuvable' not found");
        $fournisseur->genererReponse($demande);
    }
}
