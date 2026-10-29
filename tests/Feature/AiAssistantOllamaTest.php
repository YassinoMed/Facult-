<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AiAssistant\Data\AiDemande;
use App\Modules\AiAssistant\Exceptions\FournisseurIndisponibleException;
use App\Modules\AiAssistant\Providers\FournisseurOllama;
use App\Modules\AiAssistant\Services\AiChatService;
use App\Modules\AiAssistant\Support\GestionnaireFournisseur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * LOT OLLAMA (demande utilisateur / cahier LOOP-REA Lot 6 — fournisseur
 * local) : FournisseurOllama sur le contrat AiFournisseur EXISTANT de la
 * passerelle (aucune 2e architecture, aucun SDK), LOCAL-STRICT :
 *
 *  - base_url refusée si elle n'est pas en boucle locale ;
 *  - catalogue local /api/tags vérifié AVANT toute génération : modèle
 *    absent = erreur typée, JAMAIS de téléchargement déclenché ;
 *  - modèles « -cloud » et entrées sans poids réels refusés ;
 *  - AUCUN fallback cloud : service éteint => erreur typée remontée ;
 *  - aucune clé envoyée (aucun en-tête Authorization).
 *
 * Aucun service Ollama réel n'est requis : route et réponse sont
 * simulées. Le service réel n'est sollicité QUE par la mesure
 * d'exploitation documentée dans le rapport (jamais par les tests).
 */
final class AiAssistantOllamaTest extends TestCase
{
    use RefreshDatabase;

    private function demande(?array $contexte = null, ?int $jetonsMax = null): AiDemande
    {
        return new AiDemande(
            question: 'Explique la règle de réinscription.',
            mode: 'metier',
            utilisateurId: 1,
            faculteId: null,
            contexte: $contexte ?? [[
                'titre' => 'Règles d’inscription',
                'chemin' => 'docs/inscriptions/rules.md',
                'extrait' => 'Un dossier complet est requis avant la date limite.',
            ]],
            historique: [
                ['role' => 'user', 'contenu' => 'bonjour'],
                ['role' => 'assistant', 'contenu' => 'Bonjour ! Précisez votre besoin.'],
            ],
            jetonsMax: $jetonsMax,
        );
    }

    private function configurer(array $remplacements = []): void
    {
        config()->set('ai-assistant.fournisseurs.ollama', array_merge([
            'driver' => 'ollama',
            'base_url' => 'http://127.0.0.1:11434',
            'modele' => 'smollm:135m',
            'secondes' => 10,
            'options' => [],
            'verifier_modele' => true,
        ], $remplacements));
        config()->set('ai-assistant.defaut', 'ollama');
    }

    private function catalogue(array $modeles): object
    {
        return Http::response(['models' => $modeles], 200);
    }

    /* ── Résolution et restrictions de construction ────────────── */

    public function test_le_driver_ollama_est_resolu_par_la_passerelle_existante(): void
    {
        $this->configurer();

        $fournisseur = (new GestionnaireFournisseur)->actif();

        $this->assertInstanceOf(FournisseurOllama::class, $fournisseur);
        $this->assertSame('ollama', $fournisseur->nom());
        $this->assertSame('smollm:135m', $fournisseur->modele());
    }

    public function test_un_hote_dans_la_boucle_locale_est_accepte(): void
    {
        config()->set('ai-assistant.fournisseurs.ollama', [
            'driver' => 'ollama',
            'base_url' => 'http://localhost:11434',
            'modele' => 'smollm:135m',
        ]);
        config()->set('ai-assistant.defaut', 'ollama');

        $this->assertInstanceOf(FournisseurOllama::class, (new GestionnaireFournisseur)->actif());
    }

    public function test_toute_base_url_hors_boucle_locale_est_refusee_a_la_construction(): void
    {
        $horsBoucle = [
            'https://api.cloud-ollama.example/v1',
            'http://192.168.1.12:11434',
            'http://10.0.0.5:11434',
            'http://serveur-departement.universite.test:11434',
            'http://0.0.0.0:11434',
        ];

        foreach ($horsBoucle as $baseUrl) {
            config()->set('ai-assistant.fournisseurs.ollama', [
                'driver' => 'ollama',
                'base_url' => $baseUrl,
                'modele' => 'smollm:135m',
            ]);
            config()->set('ai-assistant.defaut', 'ollama');

            try {
                (new GestionnaireFournisseur)->actif();
                $this->fail("Destination hors boucle acceptée : {$baseUrl}.");
            } catch (FournisseurIndisponibleException $erreur) {
                $this->assertStringContainsString(
                    'base_url hors boucle locale refusée',
                    $erreur->getMessage()
                );
            }
        }
    }

    /* ── Catalogue local obligatoire ───────────────────────────── */

    public function test_un_modele_absent_du_catalogue_local_est_refuse_avant_toute_generation(): void
    {
        $this->configurer(['modele' => 'llama3']);
        $appelsChat = 0;

        Http::fake([
            '*/api/tags' => $this->catalogue([
                ['name' => 'smollm:135m', 'size' => 91739413],
                ['name' => 'all-minilm:latest', 'size' => 45960996],
            ]),
            '*/api/chat' => function ($request) use (&$appelsChat) {
                $appelsChat++;

                return Http::response('n’aurait jamais dû être appelé', 200);
            },
        ]);

        try {
            (new GestionnaireFournisseur)->actif()->generer($this->demande());
            $this->fail('Un modèle non installé a été utilisé.');
        } catch (FournisseurIndisponibleException $erreur) {
            $this->assertStringContainsString('non installé localement', $erreur->getMessage());
        }

        $this->assertSame(0, $appelsChat, 'JAMAIS de génération sans modèle local installé.');
    }

    public function test_un_modele_cloud_est_refuse_meme_sil_figure_dans_le_catalogue(): void
    {
        // Dossier réel de la machine : la balise cloud n’a PAS de poids
        // réels (382 octets de métadonnées) et exige un service amont.
        $this->configurer(['modele' => 'qwen3-coder:480b-cloud']);
        $appelsChat = 0;

        Http::fake([
            '*/api/tags' => $this->catalogue([
                ['name' => 'qwen3-coder:480b-cloud', 'size' => 382],
            ]),
            '*/api/chat' => function ($request) use (&$appelsChat) {
                $appelsChat++;

                return Http::response('n’aurait jamais dû être appelé', 200);
            },
        ]);

        try {
            (new GestionnaireFournisseur)->actif()->generer($this->demande());
            $this->fail('Un modèle cloud a été accepté.');
        } catch (FournisseurIndisponibleException $erreur) {
            $this->assertStringContainsString('modèle cloud refusé', $erreur->getMessage());
        }

        $this->assertSame(0, $appelsChat, 'Le flux ne doit JAMAIS quitter la boîte vers le nuage.');
    }

    public function test_une_entree_de_catalogue_sans_poids_reels_est_refusee(): void
    {
        // Nom sans suffixe cloud mais taille de simple métadonnée.
        $this->configurer(['modele' => 'modele-fantome']);
        $appelsChat = 0;

        Http::fake([
            '*/api/tags' => $this->catalogue([
                ['name' => 'modele-fantome', 'size' => 512],
            ]),
            '*/api/chat' => function ($request) use (&$appelsChat) {
                $appelsChat++;

                return Http::response('n’aurait jamais dû être appelé', 200);
            },
        ]);

        try {
            (new GestionnaireFournisseur)->actif()->generer($this->demande());
            $this->fail('Une entrée sans poids réels a été acceptée.');
        } catch (FournisseurIndisponibleException $erreur) {
            $this->assertStringContainsString('sans poids réels', $erreur->getMessage());
        }

        $this->assertSame(0, $appelsChat);
    }

    public function test_un_serveur_local_hors_service_leve_l_exception_typee_des_le_catalogue(): void
    {
        $this->configurer();
        $appelsChat = 0;

        Http::fake([
            '*/api/tags' => fn () => throw new ConnectionException('connexion refusée'),
            '*/api/chat' => function ($request) use (&$appelsChat) {
                $appelsChat++;

                return Http::response(200);
            },
        ]);

        try {
            (new GestionnaireFournisseur)->actif()->generer($this->demande());
            $this->fail('Un service éteint a été toléré.');
        } catch (FournisseurIndisponibleException $erreur) {
            $this->assertStringContainsString('service local inaccessible', $erreur->getMessage());
        }

        $this->assertSame(0, $appelsChat);
    }

    public function test_une_coupure_pendant_la_generation_est_typed_sans_fallback(): void
    {
        // Vérification du catalogue désactivée : la coupure survient sur
        // la génération elle-même — l'erreur typée remonte quand même
        // (aucun basculement vers un autre fournisseur).
        $this->configurer(['verifier_modele' => false]);

        Http::fake(fn () => throw new ConnectionException('délai dépassé'));

        try {
            (new GestionnaireFournisseur)->actif()->generer($this->demande());
            $this->fail('Une coupure de génération a été tolérée.');
        } catch (FournisseurIndisponibleException $erreur) {
            $this->assertStringContainsString('service local inaccessible', $erreur->getMessage());
        }
    }

    public function test_une_erreur_http_du_serveur_local_est_typed_sans_reprendre_le_corps(): void
    {
        $this->configurer(['verifier_modele' => false]);

        $corpsHostile = '{"error":"smollm:135m introuvable, indice interne shop_secret=ABC123"}';

        Http::fake(fn () => Http::response($corpsHostile, 500));

        try {
            (new GestionnaireFournisseur)->actif()->generer($this->demande());
            $this->fail('Une erreur HTTP 500 a été tolérée.');
        } catch (FournisseurIndisponibleException $erreur) {
            $this->assertStringContainsString('HTTP 500', $erreur->getMessage());
            $this->assertStringNotContainsString('shop_secret', $erreur->getMessage());
        }
    }

    /* ── Génération : charge utile bornée et réponse ───────────── */

    private function fauxServeur(array &$envoi, array &$entetes): void
    {
        Http::fake([
            '*/api/tags' => $this->catalogue([
                ['name' => 'smollm:135m', 'size' => 91739413],
            ]),
            '*/api/chat' => function ($request) use (&$envoi, &$entetes) {
                $envoi = $request->data();
                $entetes = $request->headers();

                return Http::response([
                    'model' => 'smollm:135m',
                    'message' => ['role' => 'assistant', 'content' => '[ollama] Dossier complet requis.'],
                    'done' => true,
                    'prompt_eval_count' => 240,
                    'eval_count' => 64,
                ], 200);
            },
        ]);
    }

    public function test_la_generation_restitue_les_champs_normalisés_et_la_citation(): void
    {
        $this->configurer();
        $envoi = [];
        $entetes = [];
        $this->fauxServeur($envoi, $entetes);

        $reponse = (new GestionnaireFournisseur)->actif()->generer($this->demande());

        $this->assertSame('[ollama] Dossier complet requis.', $reponse->texte);
        $this->assertSame('ollama', $reponse->fournisseur);
        $this->assertSame('smollm:135m', $reponse->modele);
        $this->assertSame(240, $reponse->jetonsEntree);
        $this->assertSame(64, $reponse->jetonsSortie);
        $this->assertSame(
            [['titre' => 'Règles d’inscription', 'chemin' => 'docs/inscriptions/rules.md']],
            $reponse->sources
        );
        $this->assertSame('metier', $reponse->metadonnees['mode']);
        $this->assertSame(false, $envoi['stream'], 'stream=false obligatoire (sinon flux jsonl).');
        $this->assertSame('smollm:135m', $envoi['model']);
        $this->assertArrayNotHasKey('Authorization', $entetes, 'Aucune clé envoyée à Ollama local.');
        $this->assertArrayHasKey('options', $envoi);
        $this->assertIsInt($envoi['options']['num_predict']);
        $this->assertGreaterThan(0, $envoi['options']['num_predict']);
    }

    public function test_le_budget_de_jetons_est_impose_par_la_passerelle(): void
    {
        $this->configurer();
        config()->set('ai-assistant.limites.jetons_max', 555);

        $envoi = [];
        $entetes = [];
        $this->fauxServeur($envoi, $entetes);
        $fournisseur = (new GestionnaireFournisseur)->actif();

        // Sans jetonsMax : plafond configurationnel.
        $fournisseur->generer($this->demande());
        $this->assertSame(555, $envoi['options']['num_predict']);

        // Avec jetonsMax : budget explicite borné.
        $fournisseur->generer($this->demande(jetonsMax: 777));
        $this->assertSame(777, $envoi['options']['num_predict']);
    }

    public function test_la_serialisation_transmet_systeme_historique_sources_et_question(): void
    {
        $this->configurer();
        $envoi = [];
        $entetes = [];
        $this->fauxServeur($envoi, $entetes);

        (new GestionnaireFournisseur)->actif()->generer($this->demande());

        $contenus = $envoi['messages'];
        $this->assertSame('system', $contenus[0]['role']);
        $this->assertStringContainsString('règles documentées', (string) $contenus[0]['content']);

        $roles = array_column($contenus, 'role');
        $this->assertContains('assistant', $roles, 'Historique transmis dans la fenêtre.');

        $etiquettes = array_values(array_filter(
            $contenus,
            fn ($m) => str_starts_with((string) $m['content'], 'SOURCE Fiable')
        ));
        $this->assertCount(1, $etiquettes);
        $this->assertStringContainsString('docs/inscriptions/rules.md', (string) $etiquettes[0]['content']);

        $this->assertSame('Explique la règle de réinscription.', end($contenus)['content']);
        $this->assertSame('user', end($contenus)['role']);
    }

    /* ── Intégration chat : fail-soft 502 sans fuite ───────────── */

    public function test_le_chat_repond_502_quand_le_service_local_est_eteint(): void
    {
        $agent = User::creerCompteBackOffice([
            'name' => 'Agent Ollama', 'email' => 'ollama@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');
        config(['modules.ai-assistant.active' => true]);
        $this->configurer();

        Http::fake(fn () => throw new ConnectionException('connexion refusée'));

        $id = app(AiChatService::class)
            ->creerConversation($agent, 'metier')->id;

        $reponse = $this->actingAs($agent)->postJson(
            "/admin/ai/conversations/{$id}/messages",
            ['contenu' => 'Question sans fournisseur.']
        );

        $reponse->assertStatus(502)->assertJsonPath('erreur', 'ai.fournisseur.indisponible');
        $this->assertStringNotContainsString('127.0.0.1', $reponse->getContent(), 'Aucun motif technique brut.');
        $this->assertStringNotContainsString('ollama', strtolower($reponse->getContent()), 'Le nom du fournisseur n’est pas exposé au client.');
    }
}
