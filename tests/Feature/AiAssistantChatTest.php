<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AiAssistant\Exceptions\AiAssistantException;
use App\Modules\AiAssistant\Models\AiConversation;
use App\Modules\AiAssistant\Models\AiMessage;
use App\Modules\AiAssistant\Models\AiMetrique;
use App\Modules\AiAssistant\Services\AiChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LOT 4/5 — Chat (HTTP) et persistance : flux complet sur SQLite
 * (:memory:, RefreshDatabase — migrations canoniques du lot concurrent
 * 2026_10_23_100000 + migrations additives du module).
 *
 * Couvertures : accès anonyme, 404 module désactivé, 403 sans
 * permission, création conversation (modes), envoi + réponse citée,
 * taille, plafond horaire, historique borné, ISOLATION
 * inter-utilisateurs, suppression (soft + cascade FK en dur),
 * fournisseur indisponible, pagination, ordre déterministe.
 */
final class AiAssistantChatTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        // Module activé pour ces tests (le drapeau PROD reste OFF).
        config(['modules.ai-assistant.active' => true]);

        $this->agent = User::creerCompteBackOffice([
            'name' => 'Agent IA', 'email' => 'agent-ia@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');
    }

    /* ── Accès ─────────────────────────────────────────────────── */

    public function test_le_visiteur_anonyme_ne_voit_jamaais_le_chat(): void
    {
        // Pas de route login définie dans ce back-office : l'anonyme est
        // intercepté (redirect/exception) — la seule garantie testée ici
        // est l'ABSENCE DE CONTENU.
        $statut = $this->get('/admin/ai');

        $this->assertNotSame(200, $statut->status(), 'Anonyme = jamais de contenu.');
        $this->assertStringNotContainsString('Assistant IA', $statut->getContent());
    }

    public function test_le_module_desactive_repond_404_meme_connecte(): void
    {
        config(['modules.ai-assistant.active' => false]);

        $this->actingAs($this->agent)->get('/admin/ai')->assertNotFound();
    }

    public function test_un_role_sans_permission_ia_est_refuse_403(): void
    {
        // Un rôle central sans aucune permission IA n'existe pas dans la
        // matrice : on force un rôle vide (idiome TaskBulkAuthorizationTest).
        $sansPermission = User::query()->create([
            'name' => 'Sans Rien', 'email' => 'rien@universite.test', 'password' => 'Motdepasse@123',
        ]);
        $sansPermission->forceFill(['role' => ''])->save();

        $this->actingAs($sansPermission->refresh())->get('/admin/ai')->assertForbidden();
    }

    public function test_le_membre_permissionne_consulte_l_interface(): void
    {
        $reponse = $this->actingAs($this->agent)->get('/admin/ai');

        $reponse->assertOk()
            ->assertSee('Assistant IA')
            ->assertSee('Pas de conversation active', false);
    }

    /* ── Conversations ─────────────────────────────────────────── */

    public function test_la_conversation_metier_est_creee_privee(): void
    {
        $reponse = $this->actingAs($this->agent)->post('/admin/ai/conversations', ['mode' => 'metier']);

        $reponse->assertStatus(201)
            ->assertJsonPath('conversation.mode', 'metier');

        $conversation = AiConversation::sole();

        $this->assertSame($this->agent->id, $conversation->user_id);
        $this->assertNotNull($conversation->uuid);
        $this->assertSame('Nouvelle conversation', $conversation->titre);
    }

    public function test_le_mode_developpeur_est_refuse_sans_permission(): void
    {
        $reponse = $this->actingAs($this->agent)->post('/admin/ai/conversations', ['mode' => 'developpeur']);

        $reponse->assertStatus(403)->assertJsonPath('erreur', 'ai.action.refusee');
        $this->assertSame(0, AiConversation::count());
    }

    public function test_le_mode_developpeur_est_accorde_au_super_admin(): void
    {
        $superAdmin = User::creerCompteBackOffice([
            'name' => 'Super', 'email' => 'super@universite.test', 'password' => 'Motdepasse@123',
        ], 'super_admin');

        $reponse = $this->actingAs($superAdmin)->post('/admin/ai/conversations', ['mode' => 'developpeur']);

        $reponse->assertStatus(201)->assertJsonPath('conversation.mode', 'developpeur');
        $this->assertSame(AiConversation::sole()->mode, 'developpeur');
    }

    public function test_le_mode_inconnu_est_refuse_422(): void
    {
        $reponse = $this->actingAs($this->agent)->post('/admin/ai/conversations', ['mode' => 'pirate']);

        $reponse->assertStatus(422);
    }

    /* ── Envoi de messages ─────────────────────────────────────── */

    private function creerConversation(string $mode = 'metier'): AiConversation
    {
        return app(AiChatService::class)->creerConversation($this->agent, $mode);
    }

    public function test_l_envoi_produit_une_reponse_assistant_avec_metriques(): void
    {
        $conversation = $this->creerConversation();

        $reponse = $this->actingAs($this->agent)->postJson(
            "/admin/ai/conversations/{$conversation->id}/messages",
            ['contenu' => 'Explique le cycle d’inscription.']
        );

        $reponse->assertOk()
            ->assertJsonPath('message.role', 'assistant')
            ->assertJsonPath('message.fournisseur', 'factice');
        $this->assertStringContainsString('deterministe', (string) $reponse->json('message.contenu'));

        $messages = AiMessage::query()->orderBy('id')->get();
        $this->assertSame(['user', 'assistant'], $messages->pluck('role')->all(),
            'Ordre déterministe : question puis réponse.');

        $conversation->refresh();
        $this->assertSame('factice', $conversation->fournisseur);
        $this->assertNotNull($conversation->dernier_message_a);

        $metrique = AiMetrique::sole();
        $this->assertSame('ok', $metrique->etat);
        $this->assertNotNull($metrique->latence_ms);
    }

    public function test_le_message_vide_est_refuse_422(): void
    {
        $conversation = $this->creerConversation();

        $reponse = $this->actingAs($this->agent)->postJson(
            "/admin/ai/conversations/{$conversation->id}/messages",
            ['contenu' => '   ']
        );

        $reponse->assertStatus(422);
        $this->assertSame(0, AiMessage::count());
    }

    public function test_le_message_trop_long_est_refuse_422(): void
    {
        $conversation = $this->creerConversation();

        $reponse = $this->actingAs($this->agent)->postJson(
            "/admin/ai/conversations/{$conversation->id}/messages",
            ['contenu' => str_repeat('a', 4001)]
        );

        $reponse->assertStatus(422);
        $this->assertSame(0, AiMessage::count());
    }

    public function test_le_service_refuse_un_message_trop_long_hors_validation_html(): void
    {
        $conversation = $this->creerConversation();

        $this->expectException(AiAssistantException::class);

        app(AiChatService::class)->envoyerMessage($this->agent, $conversation, str_repeat('a', 5000));
    }

    public function test_le_plafond_horaire_repond_429_des_le_depassement(): void
    {
        config(['ai-assistant.limites.appels_par_heure' => 2]);
        $conversation = $this->creerConversation();
        $endpoint = "/admin/ai/conversations/{$conversation->id}/messages";

        $this->actingAs($this->agent)->postJson($endpoint, ['contenu' => 'un'])->assertOk();
        $this->actingAs($this->agent)->postJson($endpoint, ['contenu' => 'deux'])->assertOk();

        $reponse = $this->actingAs($this->agent)->postJson($endpoint, ['contenu' => 'trois']);

        $reponse->assertStatus(429)->assertJsonPath('erreur', 'ai.plafond.atteint');

        $this->assertSame(1, AiMetrique::where('etat', 'plafond')->count(),
            'Un refus = une métrique plafond.');
    }

    public function test_le_compteur_reprend_au_changement_d_heure(): void
    {
        config(['ai-assistant.limites.appels_par_heure' => 1]);
        $conversation = $this->creerConversation();
        $endpoint = "/admin/ai/conversations/{$conversation->id}/messages";

        $this->actingAs($this->agent)->postJson($endpoint, ['contenu' => 'un'])->assertOk();
        $this->actingAs($this->agent)->postJson($endpoint, ['contenu' => 'deux'])
            ->assertStatus(429);

        // Simuler l'heure suivante : la clé d'horodatage change.
        $this->travelTo(now()->addHour());

        $this->actingAs($this->agent)->postJson($endpoint, ['contenu' => 'trois'])->assertOk();
    }

    public function test_l_historique_porte_sur_les_douze_derniers_echanges(): void
    {
        $conversation = $this->creerConversation();

        for ($i = 1; $i <= 15; $i++) {
            app(AiChatService::class)->envoyerMessage($this->agent, $conversation, "question {$i}");
        }

        $capture = null;
        $this->configurerDistantCapture($capture);

        app(AiChatService::class)->envoyerMessage($this->agent, $conversation, 'question 16');

        $payload = $capture ?? [];
        $historique = array_slice($payload['messages'] ?? [], 1, -1);

        $this->assertLessThanOrEqual(12, count($historique));
        $this->assertSame(14, count($payload['messages'] ?? []),
            'systeme + 12 derniers + question.');
        $this->assertStringContainsString('question 10', (string) $historique[0]['content'],
            'Fenêtre glissante : le plus ancien du lot est bien borné.');
    }

    public function test_l_ordre_restitue_est_strictement_chronologique(): void
    {
        $conversation = $this->creerConversation();
        $endpoint = "/admin/ai/conversations/{$conversation->id}";

        foreach (['premiere', 'deuxieme', 'troisieme'] as $mot) {
            $this->actingAs($this->agent)->postJson("{$endpoint}/messages", ['contenu' => $mot])->assertOk();
        }

        $reponse = $this->actingAs($this->agent)->getJson($endpoint)->assertOk();

        $ids = collect($reponse->json('messages'))->pluck('id')->all();
        $roles = collect($reponse->json('messages'))->pluck('role')->all();

        $this->assertSame($ids, collect($ids)->sort()->values()->all(), 'Ordre ascendant strict.');
        $this->assertSame(['user', 'assistant', 'user', 'assistant', 'user', 'assistant'], $roles);
    }

    /* ── Isolation inter-utilisateurs ──────────────────────────── */

    public function test_l_isolation_bloque_lecture_envoi_et_suppression_d_autrui(): void
    {
        $conversation = $this->creerConversation();
        app(AiChatService::class)->envoyerMessage($this->agent, $conversation, 'confidentiel');

        $autre = User::creerCompteBackOffice([
            'name' => 'Autre', 'email' => 'autre@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        $this->actingAs($autre)->getJson("/admin/ai/conversations/{$conversation->id}")
            ->assertNotFound();
        $this->actingAs($autre)->postJson("/admin/ai/conversations/{$conversation->id}/messages",
            ['contenu' => 'espionnage'])->assertNotFound();
        $this->actingAs($autre)->deleteJson("/admin/ai/conversations/{$conversation->id}")
            ->assertNotFound();
        $this->actingAs($autre)->getJson('/admin/ai/conversations')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($autre)->get('/admin/ai')
            ->assertOk()->assertDontSee('confidentiel');

        // Défense en profondeur : le service refuse aussi.
        $this->expectException(AiAssistantException::class);
        app(AiChatService::class)->envoyerMessage($autre, $conversation, 'espionnage service');
    }

    public function test_la_liste_ne_renvoie_que_les_conversations_du_proprietaire(): void
    {
        $this->creerConversation();
        $conversationAutre = AiConversation::create([
            'uuid' => Str::uuid()->toString(),
            'user_id' => User::creerCompteBackOffice([
                'name' => 'Voisin', 'email' => 'voisin@universite.test', 'password' => 'Motdepasse@123',
            ], 'agent_scolarite')->id,
            'titre' => 'Interlope',
            'mode' => 'metier',
        ]);

        $reponse = $this->actingAs($this->agent)->getJson('/admin/ai/conversations?per_page=50')->assertOk();

        $ids = collect($reponse->json('data'))->pluck('id');
        $this->assertNotContains($conversationAutre->id, $ids->all(),
            'aucune conversation d’autrui dans la liste');
        $this->assertSame(1, $reponse->json('total'));
    }

    /* ── Suppression / cascade ─────────────────────────────────── */

    public function test_la_suppression_est_douce_puis_la_cache_fk_purge_en_dur(): void
    {
        $conversation = $this->creerConversation();
        app(AiChatService::class)->envoyerMessage($this->agent, $conversation, 'à purger');
        $this->assertSame(2, AiMessage::count());

        $this->actingAs($this->agent)->deleteJson("/admin/ai/conversations/{$conversation->id}")
            ->assertOk()->assertJsonPath('supprime', true);

        $this->assertSame(1, AiConversation::withTrashed()->count(),
            'Soft-delete : suppression logique conservée.');
        $this->assertSame(2, AiMessage::count());

        $conversation->forceDelete();

        $this->assertSame(0, AiMessage::count(),
            'FK canonique ON DELETE CASCADE : purgé en dur.');
    }

    public function test_une_conversation_supprimee_sort_de_l_interface(): void
    {
        $conversation = $this->creerConversation();

        $this->actingAs($this->agent)->deleteJson("/admin/ai/conversations/{$conversation->id}")->assertOk();

        $this->actingAs($this->agent)->getJson('/admin/ai/conversations')
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('total', 0);
    }

    /* ── Fournisseur indisponible ──────────────────────────────── */

    public function test_l_echec_fournisseur_repond_502_et_conserve_le_message_utilisateur(): void
    {
        $conversation = $this->creerConversation();

        config(['ai-assistant.fournisseurs.distant_compat' => [
            'driver' => 'compatible_openai',
            'base_url' => 'https://fournisseur-en-panne.example/v1',
            'modele' => 'modele-test',
            'cle' => 'CLE_SYNTHETIQUE_TEST',
            'secondes' => 2,
        ]]);
        config(['ai-assistant.defaut' => 'distant_compat']);

        Http::fake(function () {
            throw new ConnectionException('délai dépassé'); // Timeout simulé.
        });

        $reponse = $this->actingAs($this->agent)->postJson(
            "/admin/ai/conversations/{$conversation->id}/messages",
            ['contenu' => 'premier essai']
        );

        $reponse->assertStatus(502)->assertJsonPath('erreur', 'ai.fournisseur.indisponible');

        $messages = AiMessage::query()->orderBy('id')->get();
        $this->assertSame(['user'], $messages->pluck('role')->all(),
            'Question conservée, aucune réponse fabriquée.');

        $this->assertSame(
            1,
            AiMetrique::where('etat', 'echec')->where('code_erreur', 'ai.fournisseur.indisponible')->count()
        );
    }

    /* ── Pagination ═══════════════════════════════════════════ */

    public function test_la_pagination_des_conversations_separe_par_fenetres_de_20(): void
    {
        $service = app(AiChatService::class);

        for ($i = 1; $i <= 25; $i++) {
            $service->creerConversation($this->agent, 'metier', null, "Conv {$i}");
        }

        $premiere = $this->actingAs($this->agent)->getJson('/admin/ai/conversations?page=1')->assertOk();
        $seconde = $this->actingAs($this->agent)->getJson('/admin/ai/conversations?page=2')->assertOk();

        $this->assertSame(20, count($premiere->json('data')));
        $this->assertSame(5, count($seconde->json('data')));
        $this->assertSame(25, $seconde->json('total'));
    }

    /* ── Utilitaires ───────────────────────────────────────────── */

    private function configurerDistantCapture(?array &$capture = null): void
    {
        config(['ai-assistant.fournisseurs.distant_compat' => [
            'driver' => 'compatible_openai',
            'base_url' => 'https://capture-historique.example/v1',
            'modele' => 'modele-test',
            'cle' => 'CLE_SYNTHETIQUE_TEST',
            'secondes' => 2,
        ]]);
        config(['ai-assistant.defaut' => 'distant_compat']);

        Http::fake(function ($request) use (&$capture) {
            $capture = $request->data();

            return Http::response([
                'choices' => [['message' => ['content' => 'réponse de test']]],
                'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 2],
            ], 200);
        });
    }
}
