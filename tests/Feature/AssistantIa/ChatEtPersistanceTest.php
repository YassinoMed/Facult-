<?php

namespace Tests\Feature\AssistantIa;

use App\Models\User;
use App\Modules\AssistantIa\Models\AiConversation;
use App\Modules\AssistantIa\Models\AiMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatEtPersistanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_utilisateur_non_authentifie_est_redirige(): void
    {
        $response = $this->get(route('admin.assistant-ia.index'));
        $response->assertRedirect('/admin/connexion');
    }

    public function test_utilisateur_sans_permission_recoit_403(): void
    {
        $user = User::factory()->create();
        $user->definirRole('consultation');
        // On retire temporairement les permissions ou on utilise un compte sans permission
        // Notons que 'consultation' a maintenant assistant_ia.view.
        // Créons un utilisateur avec un rôle non privilégié ou sans permission
        $userSansDroit = User::factory()->create();
        $userSansDroit->role = 'etudiant'; // rôle sans accès back-office
        $userSansDroit->save();

        $response = $this->actingAs($userSansDroit)->get(route('admin.assistant-ia.index'));
        $response->assertForbidden();
    }

    public function test_utilisateur_autorise_accede_au_cockpit(): void
    {
        $admin = User::factory()->create();
        $admin->definirRole('admin_scolarite');

        $response = $this->actingAs($admin)->get(route('admin.assistant-ia.index'));
        $response->assertOk();
        $response->assertViewIs('modules.assistant-ia.index');
        $response->assertSee('UniRegistre AI');
    }

    public function test_creation_conversation_avec_isolation_et_uuid(): void
    {
        $admin = User::factory()->create();
        $admin->definirRole('admin_scolarite');

        $response = $this->actingAs($admin)->post(route('admin.assistant-ia.conversations.creer'), [
            'mode' => 'metier',
            'titre' => 'Diagnostic scolarité 2026',
        ]);

        $conversation = AiConversation::where('user_id', $admin->id)->first();
        $this->assertNotNull($conversation);
        $this->assertNotEmpty($conversation->uuid);
        $this->assertSame('Diagnostic scolarité 2026', $conversation->titre);
        $this->assertSame('metier', $conversation->mode);

        $response->assertRedirect(route('admin.assistant-ia.conversations.show', $conversation->uuid));
    }

    public function test_utilisateur_sans_droit_dev_ne_peut_pas_creer_conversation_mode_developpeur(): void
    {
        $agent = User::factory()->create();
        $agent->definirRole('agent_scolarite'); // N'a pas assistant_ia.mode_developpeur

        $response = $this->actingAs($agent)->post(route('admin.assistant-ia.conversations.creer'), [
            'mode' => 'developpeur',
        ]);

        $response->assertForbidden();
    }

    public function test_isolation_horizontale_stricte_anti_idor_entre_utilisateurs(): void
    {
        $userA = User::factory()->create();
        $userA->definirRole('admin_scolarite');

        $userB = User::factory()->create();
        $userB->definirRole('admin_scolarite');

        $conversationA = AiConversation::create([
            'user_id' => $userA->id,
            'titre' => 'Conversation privée de A',
            'mode' => 'metier',
        ]);

        // User B tente d'accéder à la conversation de User A -> Doit être 404 (non trouvé dans son scope)
        $response = $this->actingAs($userB)->get(route('admin.assistant-ia.conversations.show', $conversationA->uuid));
        $response->assertNotFound();

        // User B tente d'envoyer un message sur la conversation de User A -> 404
        $responsePost = $this->actingAs($userB)->postJson(route('admin.assistant-ia.messages.envoyer', $conversationA->uuid), [
            'contenu' => 'Tentative de piratage horizontal',
        ]);
        $responsePost->assertNotFound();
    }

    public function test_envoi_message_et_reponse_agentique(): void
    {
        $admin = User::factory()->create();
        $admin->definirRole('admin_scolarite');

        $conversation = AiConversation::create([
            'user_id' => $admin->id,
            'titre' => 'Nouvelle conversation',
            'mode' => 'metier',
        ]);

        $response = $this->actingAs($admin)->postJson(route('admin.assistant-ia.messages.envoyer', $conversation->uuid), [
            'contenu' => 'Quelles sont les pièces requises pour une inscription ?',
        ]);

        $response->assertOk();
        $response->assertJson([
            'succes' => true,
        ]);

        // Vérification de la persistance des deux messages (user et assistant)
        $messages = AiMessage::where('conversation_id', $conversation->id)->orderBy('id')->get();
        $this->assertCount(2, $messages);

        $msgUser = $messages[0];
        $this->assertSame('user', $msgUser->role);
        $this->assertSame('Quelles sont les pièces requises pour une inscription ?', $msgUser->contenu);

        $msgAssistant = $messages[1];
        $this->assertSame('assistant', $msgAssistant->role);
        $this->assertNotEmpty($msgAssistant->contenu);
    }

    public function test_suppression_logique_conversation(): void
    {
        $admin = User::factory()->create();
        $admin->definirRole('admin_scolarite');

        $conversation = AiConversation::create([
            'user_id' => $admin->id,
            'titre' => 'Conversation à supprimer',
            'mode' => 'metier',
        ]);

        $response = $this->actingAs($admin)->delete(route('admin.assistant-ia.conversations.supprimer', $conversation->uuid));
        $response->assertRedirect(route('admin.assistant-ia.index'));

        // Soft deleted
        $this->assertSoftDeleted('ai_conversations', ['id' => $conversation->id]);

        // Ne peut plus être ouverte
        $this->actingAs($admin)->get(route('admin.assistant-ia.conversations.show', $conversation->uuid))
            ->assertNotFound();
    }
}
