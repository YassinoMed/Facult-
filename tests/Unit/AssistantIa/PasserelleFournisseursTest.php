<?php

namespace Tests\Unit\AssistantIa;

use App\Models\User;
use App\Modules\AssistantIa\Drivers\FauxFournisseurIa;
use App\Modules\AssistantIa\Drivers\FournisseurLocal;
use App\Modules\AssistantIa\Drivers\FournisseurRestDistant;
use App\Modules\AssistantIa\DTO\DemandeIa;
use App\Modules\AssistantIa\DTO\MessageIa;
use App\Modules\AssistantIa\Exceptions\QuotasDepassesException;
use App\Modules\AssistantIa\Services\PasserelleFournisseurs;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PasserelleFournisseursTest extends TestCase
{
    public function test_passerelle_selectionne_fournisseur_par_defaut(): void
    {
        $passerelle = new PasserelleFournisseurs;
        $fournisseur = $passerelle->obtenirFournisseur();

        $this->assertInstanceOf(FauxFournisseurIa::class, $fournisseur);
        $this->assertSame('faux', $fournisseur->nomFournisseur());
        $this->assertTrue($fournisseur->estDisponible());
    }

    public function test_faux_fournisseur_renvoie_reponses_deterministes_et_enregistre_historique(): void
    {
        $faux = new FauxFournisseurIa('test-model-mock');
        $faux->empilerReponseTexte('Première réponse programmée', 10, 15);

        $demande1 = new DemandeIa([
            MessageIa::utilisateur('Bonjour assistant'),
        ]);

        $reponse1 = $faux->genererReponse($demande1);
        $this->assertSame('Première réponse programmée', $reponse1->texte);
        $this->assertSame('faux', $reponse1->fournisseur);
        $this->assertSame(25, $reponse1->totalTokens());

        // Demande suivante sans réponse programmée -> écho contrôlé
        $demande2 = new DemandeIa([
            MessageIa::utilisateur('Deuxième question'),
        ]);

        $reponse2 = $faux->genererReponse($demande2);
        $this->assertStringContainsString('Deuxième question', $reponse2->texte);
        $this->assertCount(2, $faux->demandesRecues());
    }

    public function test_passerelle_applique_les_plafonds_de_quotas_utilisateur(): void
    {
        Cache::flush();

        $user = new User;
        $user->id = 42;
        $user->role = 'consultation'; // Quota configuré à 50

        $config = config('assistant-ia');
        $config['limites']['quotas_journaliers_utilisateur']['consultation'] = 2; // Forcer à 2 pour le test

        $passerelle = new PasserelleFournisseurs($config);
        $demande = new DemandeIa([MessageIa::utilisateur('Question 1')]);

        // Appel 1 : Succès
        $rep1 = $passerelle->genererReponse($demande, $user);
        $this->assertNotNull($rep1);

        $stats1 = $passerelle->consommationUtilisateur($user);
        $this->assertSame(1, $stats1['consomme']);
        $this->assertSame(1, $stats1['restant']);

        // Appel 2 : Succès
        $rep2 = $passerelle->genererReponse($demande, $user);
        $this->assertNotNull($rep2);

        $stats2 = $passerelle->consommationUtilisateur($user);
        $this->assertSame(2, $stats2['consomme']);
        $this->assertSame(0, $stats2['restant']);

        // Appel 3 : Doit lever QuotasDepassesException
        $this->expectException(QuotasDepassesException::class);
        $passerelle->genererReponse($demande, $user);
    }

    public function test_fournisseur_distant_avec_http_fake(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-test123',
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Réponse distante simulée avec succès.',
                            'tool_calls' => [
                                [
                                    'id' => 'call_abc',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'diagnostiquer_inscription',
                                        'arguments' => json_encode(['dossier_id' => 101]),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 45,
                    'completion_tokens' => 25,
                ],
            ], 200),
        ]);

        $distant = new FournisseurRestDistant(
            baseUrl: 'https://api.openai.com/v1',
            cleApi: 'sk-test-mock-key',
            modele: 'gpt-4o-mini'
        );

        $demande = new DemandeIa(
            messages: [MessageIa::utilisateur('Peux-tu vérifier le dossier ?')],
            outilsDisponibles: [
                ['type' => 'function', 'function' => ['name' => 'diagnostiquer_inscription']],
            ]
        );

        $reponse = $distant->genererReponse($demande);

        $this->assertSame('distant', $reponse->fournisseur);
        $this->assertSame('Réponse distante simulée avec succès.', $reponse->texte);
        $this->assertSame(70, $reponse->totalTokens());
        $this->assertTrue($reponse->aDesAppelsOutils());
        $this->assertCount(1, $reponse->appelsOutils);
        $this->assertSame('diagnostiquer_inscription', $reponse->appelsOutils[0]->nomOutil);
        $this->assertSame(101, $reponse->appelsOutils[0]->arguments['dossier_id']);
    }

    public function test_fournisseur_distant_gere_erreur_429_quota(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'error' => ['message' => 'Rate limit exceeded'],
            ], 429),
        ]);

        $distant = new FournisseurRestDistant(
            baseUrl: 'https://api.openai.com/v1',
            cleApi: 'sk-test-mock-key',
            modele: 'gpt-4o-mini'
        );

        $demande = new DemandeIa([MessageIa::utilisateur('Hello')]);

        $this->expectException(QuotasDepassesException::class);
        $distant->genererReponse($demande);
    }

    public function test_fournisseur_local_avec_http_fake(): void
    {
        Http::fake([
            'http://127.0.0.1:11434/v1/chat/completions' => Http::response([
                'id' => 'ollama-test-456',
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Réponse locale souveraine Ollama.',
                        ],
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 30,
                    'completion_tokens' => 40,
                ],
            ], 200),
        ]);

        $local = new FournisseurLocal(
            baseUrl: 'http://127.0.0.1:11434/v1',
            modele: 'qwen2.5-coder:7b'
        );

        $demande = new DemandeIa([MessageIa::utilisateur('Explique-moi le modèle')]);
        $reponse = $local->genererReponse($demande);

        $this->assertSame('local', $reponse->fournisseur);
        $this->assertSame('Réponse locale souveraine Ollama.', $reponse->texte);
        $this->assertSame(70, $reponse->totalTokens());
    }
}
