<?php

namespace Tests\Unit\AssistantIa;

use App\Models\User;
use App\Modules\AssistantIa\Models\AiConversation;
use App\Modules\AssistantIa\Models\AiMessage;
use App\Modules\AssistantIa\Models\AiPropositionAction;
use App\Modules\AssistantIa\Services\GestionnaireMemoireIa;
use App\Modules\AssistantIa\Services\ObservabiliteIaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemoireEtObservabiliteTest extends TestCase
{
    use RefreshDatabase;

    public function test_extraction_contexte_glissant_limite_aux_n_derniers_messages(): void
    {
        $user = User::factory()->create();
        $conv = AiConversation::create(['user_id' => $user->id, 'titre' => 'Test Contexte']);

        for ($i = 1; $i <= 25; $i++) {
            AiMessage::create([
                'conversation_id' => $conv->id,
                'role' => $i % 2 === 1 ? 'user' : 'assistant',
                'contenu' => "Message {$i}",
            ]);
        }

        $memoire = new GestionnaireMemoireIa;
        $contexte = $memoire->extraireContexteGlissant($conv, 10);

        $this->assertCount(10, $contexte);
        $this->assertSame('Message 16', $contexte[0]['content']);
        $this->assertSame('Message 25', $contexte[9]['content']);
    }

    public function test_politique_de_retention_purge_conversations_supprimees_anciennes(): void
    {
        $user = User::factory()->create();
        $memoire = new GestionnaireMemoireIa;

        // 1. Conversation supprimée il y a 100 jours (> 90 jours)
        $convAncienne = AiConversation::create(['user_id' => $user->id, 'titre' => 'Très ancienne']);
        $convAncienne->delete();
        $convAncienne->deleted_at = Carbon::now()->subDays(100);
        $convAncienne->saveQuietly();

        // 2. Conversation supprimée récemment (il y a 10 jours)
        $convRecente = AiConversation::create(['user_id' => $user->id, 'titre' => 'Récente']);
        $convRecente->delete();
        $convRecente->deleted_at = Carbon::now()->subDays(10);
        $convRecente->saveQuietly();

        $nbPurges = $memoire->purgerConversationsObsoletes(90);

        $this->assertSame(1, $nbPurges);
        $this->assertDatabaseMissing('ai_conversations', ['id' => $convAncienne->id]);
        $this->assertDatabaseHas('ai_conversations', ['id' => $convRecente->id]);
    }

    public function test_observabilite_calcule_metriques_globales_sans_exposer_de_pii(): void
    {
        $user = User::factory()->create();
        $conv = AiConversation::create(['user_id' => $user->id, 'titre' => 'Discussion Métriques']);

        AiMessage::create([
            'conversation_id' => $conv->id,
            'role' => 'user',
            'contenu' => 'Question avec CIN 12345678',
        ]);

        AiMessage::create([
            'conversation_id' => $conv->id,
            'role' => 'assistant',
            'contenu' => 'Réponse officielle',
            'tokens_prompt' => 50,
            'tokens_reponse' => 100,
            'latence_ms' => 120.5,
            'appels_outils' => [
                ['outil' => 'diagnostiquer_blocage_dossier'],
            ],
            'sources' => [
                ['titre' => 'Règlement LMD'],
            ],
        ]);

        AiPropositionAction::create([
            'user_id' => $user->id,
            'type_action' => 'debloquer_dossier',
            'ressource_type' => 'InscriptionAcademique',
            'ressource_id' => 1,
            'description' => 'Déblocage',
            'parametres' => ['test' => true],
            'resume_impact' => 'Impact',
            'statut' => 'pending_approval',
        ]);

        $observabilite = new ObservabiliteIaService;
        $metriques = $observabilite->metriquesGlobales();

        $this->assertSame(1, $metriques['total_conversations']);
        $this->assertSame(2, $metriques['total_messages']);
        $this->assertSame(50, $metriques['total_tokens_prompt']);
        $this->assertSame(100, $metriques['total_tokens_reponse']);
        $this->assertSame(150, $metriques['total_tokens']);
        $this->assertSame(120.5, $metriques['latence_moyenne_ms']);
        $this->assertSame(100.0, $metriques['taux_reponses_sourcees_pct']);
        $this->assertSame(1, $metriques['approbations']['en_attente']);
        $this->assertArrayHasKey('diagnostiquer_blocage_dossier', $metriques['frequence_outils']);

        // Aucune trace de CIN dans les métriques
        $jsonMetriques = json_encode($metriques);
        $this->assertStringNotContainsString('12345678', $jsonMetriques);
    }
}
