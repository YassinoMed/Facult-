<?php

namespace Tests\Unit\AssistantIa;

use App\Modules\AssistantIa\Drivers\FournisseurGroqCloud;
use App\Modules\AssistantIa\DTO\DemandeIa;
use App\Modules\AssistantIa\DTO\MessageIa;
use App\Modules\AssistantIa\Exceptions\AssistantIaException;
use App\Modules\AssistantIa\Exceptions\QuotasDepassesException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FournisseurGroqCloudTest extends TestCase
{
    protected string $mockBaseUrl = 'https://api.groq.com/openai/v1';

    public function test_fournisseur_identifiants_canoniques_et_configuration(): void
    {
        $groq = new FournisseurGroqCloud(
            baseUrl: 'https://api.groq.com/openai/v1',
            cleApi: 'gsk_test_secret_key_12345',
            modele: 'llama-3.3-70b-versatile',
            timeoutSecondes: 25,
            connectTimeoutSecondes: 4
        );

        $this->assertSame('groq', $groq->nomFournisseur());
        $this->assertSame('llama-3.3-70b-versatile', $groq->nomModele());
    }

    public function test_modele_est_facilement_configurable(): void
    {
        $groq1 = new FournisseurGroqCloud(cleApi: 'gsk_test', modele: 'llama-3.1-8b-instant');
        $this->assertSame('llama-3.1-8b-instant', $groq1->nomModele());

        $groq2 = new FournisseurGroqCloud(cleApi: 'gsk_test', modele: 'mixtral-8x7b-32768');
        $this->assertSame('mixtral-8x7b-32768', $groq2->nomModele());

        $groq3 = new FournisseurGroqCloud(cleApi: 'gsk_test', modele: 'openai/gpt-oss-20b');
        $this->assertSame('openai/gpt-oss-20b', $groq3->nomModele());
    }

    public function test_rejet_strict_du_protocole_non_chiffre_http(): void
    {
        $this->expectException(AssistantIaException::class);
        $this->expectExceptionCode(400);

        new FournisseurGroqCloud(
            baseUrl: 'http://insecure-api.groq.com/openai/v1',
            cleApi: 'gsk_test'
        );
    }

    public function test_verification_de_disponibilite_succes_et_echec(): void
    {
        Http::fake([
            'https://api.groq.com/openai/v1/models' => Http::sequence()
                ->push(['data' => [['id' => 'llama-3.3-70b-versatile']]], 200)
                ->push(null, 503),
        ]);

        $groq = new FournisseurGroqCloud(cleApi: 'gsk_test');
        $this->assertTrue($groq->estDisponible());
        $this->assertFalse($groq->estDisponible());
    }

    public function test_appel_normalise_avec_succes_reponse_tokens_et_identifiant(): void
    {
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-groq-98765',
                'object' => 'chat.completion',
                'created' => 1729700000,
                'model' => 'llama-3.3-70b-versatile',
                'system_fingerprint' => 'fp_groq_01',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Pour valider votre inscription académique, vous devez déposer votre relevé du baccalauréat.',
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 45,
                    'completion_tokens' => 18,
                    'total_tokens' => 63,
                ],
            ], 200),
        ]);

        $groq = new FournisseurGroqCloud(cleApi: 'gsk_valid_key', modele: 'llama-3.3-70b-versatile');
        $demande = new DemandeIa(
            messages: [MessageIa::utilisateur('Quels sont les documents pour valider mon inscription ?')],
            promptSysteme: 'Tu es l\'assistant UniRegistre.',
            temperature: 0.2,
            maxTokens: 500
        );

        $reponse = $groq->genererReponse($demande);

        $this->assertSame('Pour valider votre inscription académique, vous devez déposer votre relevé du baccalauréat.', $reponse->texte);
        $this->assertSame('groq', $reponse->fournisseur);
        $this->assertSame('llama-3.3-70b-versatile', $reponse->modele);
        $this->assertSame(45, $reponse->tokensPrompt);
        $this->assertSame(18, $reponse->tokensReponse);
        $this->assertSame(63, $reponse->totalTokens());
        $this->assertSame('chatcmpl-groq-98765', $reponse->metadata['id_reponse'] ?? null);
        $this->assertGreaterThanOrEqual(0.0, $reponse->latenceMs);

        // Vérification de la structure de payload envoyée à Groq
        Http::assertSent(function ($request) {
            $data = $request->data();

            return $request->hasHeader('Authorization', 'Bearer gsk_valid_key')
                && $data['model'] === 'llama-3.3-70b-versatile'
                && $data['max_completion_tokens'] === 500
                && $data['temperature'] === 0.2
                && count($data['messages']) >= 2;
        });
    }

    public function test_gestion_explicite_erreur_400_requete_invalide(): void
    {
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'error' => ['message' => 'Invalid temperature value', 'type' => 'invalid_request_error'],
            ], 400),
        ]);

        $groq = new FournisseurGroqCloud(cleApi: 'gsk_test');
        $demande = new DemandeIa([MessageIa::utilisateur('Test')]);

        $this->expectException(AssistantIaException::class);
        $this->expectExceptionCode(400);

        $groq->genererReponse($demande);
    }

    public function test_gestion_explicite_erreur_401_cle_invalide(): void
    {
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'error' => ['message' => 'Invalid API Key provided', 'type' => 'invalid_api_key'],
            ], 401),
        ]);

        $groq = new FournisseurGroqCloud(cleApi: 'gsk_invalid_key');
        $demande = new DemandeIa([MessageIa::utilisateur('Test')]);

        $this->expectException(AssistantIaException::class);
        $this->expectExceptionCode(401);

        $groq->genererReponse($demande);
    }

    public function test_gestion_explicite_erreur_403_acces_refuse(): void
    {
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'error' => ['message' => 'Country or IP not supported', 'type' => 'permission_denied'],
            ], 403),
        ]);

        $groq = new FournisseurGroqCloud(cleApi: 'gsk_test');
        $demande = new DemandeIa([MessageIa::utilisateur('Test')]);

        $this->expectException(AssistantIaException::class);
        $this->expectExceptionCode(403);

        $groq->genererReponse($demande);
    }

    public function test_gestion_explicite_erreur_404_modele_introuvable(): void
    {
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'error' => ['message' => 'Model not found', 'type' => 'model_not_found'],
            ], 404),
        ]);

        $groq = new FournisseurGroqCloud(cleApi: 'gsk_test', modele: 'modele-inexistant');
        $demande = new DemandeIa([MessageIa::utilisateur('Test')]);

        $this->expectException(AssistantIaException::class);
        $this->expectExceptionCode(404);

        $groq->genererReponse($demande);
    }

    public function test_gestion_explicite_erreur_429_quota_et_debit_depasse(): void
    {
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'error' => ['message' => 'Rate limit reached for requests per minute', 'type' => 'tokens'],
            ], 429),
        ]);

        $groq = new FournisseurGroqCloud(cleApi: 'gsk_test');
        $demande = new DemandeIa([MessageIa::utilisateur('Test')]);

        $this->expectException(QuotasDepassesException::class);

        $groq->genererReponse($demande);
    }

    public function test_gestion_explicite_erreur_500_fournisseur(): void
    {
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response('Internal Server Error', 500),
        ]);

        $groq = new FournisseurGroqCloud(cleApi: 'gsk_test');
        $demande = new DemandeIa([MessageIa::utilisateur('Test')]);

        $this->expectException(AssistantIaException::class);
        $this->expectExceptionCode(500);

        $groq->genererReponse($demande);
    }

    public function test_gestion_timeout_et_panne_reseau(): void
    {
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => function () {
                throw new ConnectionException('Connection timed out after 5000 milliseconds');
            },
        ]);

        $groq = new FournisseurGroqCloud(cleApi: 'gsk_test');
        $demande = new DemandeIa([MessageIa::utilisateur('Test')]);

        $this->expectException(AssistantIaException::class);
        $this->expectExceptionCode(504);

        $groq->genererReponse($demande);
    }

    public function test_reponse_vide_ou_structure_json_invalide(): void
    {
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response('NOT JSON', 200, ['Content-Type' => 'text/html']),
        ]);

        $groq = new FournisseurGroqCloud(cleApi: 'gsk_test');
        $demande = new DemandeIa([MessageIa::utilisateur('Test')]);

        $this->expectException(AssistantIaException::class);
        $this->expectExceptionCode(502);

        $groq->genererReponse($demande);
    }

    public function test_exclusion_stricte_des_outils_de_mutation_du_payload_distant(): void
    {
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'id' => 'call-1',
                'choices' => [['message' => ['content' => 'Lecture effectuée']]],
            ], 200),
        ]);

        $outils = [
            [
                'type' => 'function',
                'function' => ['name' => 'rechercher_document', 'description' => 'Lecture seule'],
            ],
            [
                'type' => 'function',
                'function' => ['name' => 'modifier_note_etudiant', 'description' => 'Mutation interdite'],
            ],
            [
                'type' => 'function',
                'function' => ['name' => 'supprimer_inscription', 'description' => 'Suppression interdite'],
            ],
            [
                'type' => 'function',
                'function' => ['name' => 'preparer_action_sensible', 'description' => 'Mutation sensible'],
            ],
        ];

        $groq = new FournisseurGroqCloud(cleApi: 'gsk_test');
        $demande = new DemandeIa(
            messages: [MessageIa::utilisateur('Audit')],
            outilsDisponibles: $outils
        );

        $groq->genererReponse($demande);

        Http::assertSent(function ($request) {
            $tools = $request->data()['tools'] ?? [];
            if (count($tools) !== 1) {
                return false;
            }

            return $tools[0]['function']['name'] === 'rechercher_document';
        });
    }

    public function test_protection_contre_depassement_du_volume_de_reponse(): void
    {
        // Réponse supérieure à 512 Ko
        $grandTexte = str_repeat('A', 550000);

        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response($grandTexte, 200),
        ]);

        $groq = new FournisseurGroqCloud(cleApi: 'gsk_test');
        $demande = new DemandeIa([MessageIa::utilisateur('Test')]);

        $this->expectException(AssistantIaException::class);
        $this->expectExceptionCode(502);

        $groq->genererReponse($demande);
    }
}
