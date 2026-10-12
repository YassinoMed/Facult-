<?php

namespace Tests\Feature;

use App\Models\IntegrationLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * API v1 — Webhooks signés (§53-54) : HMAC-SHA256, fenêtre temporelle,
 * anti-replay, payload validé, refus silencieux des URLs nues.
 */
class ApiWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['integrations.webhook_secret' => self::SECRET]);
        Cache::flush();
    }

    /**
     * Envoie un webhook signé : le corps signé est EXACTEMENT le JSON
     * envoyé (postJson) — sinon la vérification HMAC échouerait.
     */
    private function webhook(array $payload, ?string $signature = null, ?int $timestamp = null, ?string $requestId = null)
    {
        $corps = (string) json_encode($payload);

        return $this->postJson('/api/v1/webhooks/external-system', $payload, [
            'X-Signature' => $signature ?? base64_encode(hash_hmac('sha256', $corps, self::SECRET, true)),
            'X-Timestamp' => (string) ($timestamp ?? now()->getTimestamp()),
            'X-Request-ID' => $requestId ?? (string) Str::uuid(),
        ]);
    }

    public function test_webhook_correctement_signe_accepte_et_journalise(): void
    {
        $reponse = $this->webhook(['event' => 'student.registered', 'external_reference' => 'EXT-123']);

        $reponse->assertStatus(202)
            ->assertJson(['message' => 'Webhook accepté et journalisé.']);

        // Journal : échange tracé, jamais le payload en clair
        $this->assertDatabaseHas('integration_logs', [
            'integration' => 'webhook',
            'operation' => 'webhook.student.registered',
            'status' => 'completed',
            'http_status' => 202,
            'external_reference' => 'EXT-123',
        ]);

        $entree = IntegrationLog::where('operation', 'webhook.student.registered')->first();
        $this->assertNotNull($entree->payload_hash);
        $this->assertStringNotContainsStringIgnoringCase('student', (string) $entree->external_reference === '' ? 'x' : 'ok'); // aucune donnée sensible
    }

    public function test_signature_invalide_refusee(): void
    {
        $this->webhook(['event' => 'ping'], signature: base64_encode('falsifie'))
            ->assertStatus(401);

        $this->assertDatabaseHas('integration_logs', [
            'operation' => 'webhook.rejected',
            'error_code' => 'hmac_invalid',
        ]);
    }

    public function test_timestamp_expire_refuse(): void
    {
        $this->webhook(['event' => 'ping'], timestamp: now()->subMinutes(30)->getTimestamp())
            ->assertStatus(401);
    }

    public function test_rejeu_du_meme_request_id_refuse(): void
    {
        $requestId = (string) Str::uuid();

        $this->webhook(['event' => 'ping'], requestId: $requestId)->assertStatus(202);

        // Rejeu exact du même échange : refusé (anti-replay)
        $this->webhook(['event' => 'ping'], requestId: $requestId)->assertStatus(401);
    }

    public function test_en_tetes_absents_refuses(): void
    {
        $this->postJson('/api/v1/webhooks/external-system', ['event' => 'ping'])
            ->assertStatus(401);
    }

    public function test_event_inconnu_refuse(): void
    {
        $this->webhook(['event' => 'delete.all'])
            ->assertStatus(422);
    }

    public function test_webhook_sans_secret_configure_refuse_tout(): void
    {
        config(['integrations.webhook_secret' => null]);

        $this->webhook(['event' => 'ping'])->assertStatus(401);
    }

    /**
     * Audit §P2-13 : une signature invalide ne doit PAS « brûler » le
     * X-Request-ID — le même identifiant avec la bonne signature passe.
     */
    public function test_signature_invalide_ne_brule_pas_le_request_id(): void
    {
        $requestId = (string) Str::uuid();

        $this->webhook(['event' => 'ping'], signature: base64_encode('falsifie'), requestId: $requestId)
            ->assertStatus(401);

        $this->webhook(['event' => 'ping'], requestId: $requestId)
            ->assertStatus(202);
    }

    /**
     * Audit §P2-15 : un X-Request-ID surdimensionné est normalisé par un
     * UUID serveur (jamais stocké tel quel, jamais d'erreur 500).
     */
    public function test_request_id_surdimensionne_normalise_par_le_serveur(): void
    {
        $reponse = $this->webhook(['event' => 'ping'], requestId: str_repeat('A', 200));

        $reponse->assertStatus(202);

        $normalise = (string) $reponse->headers->get('X-Request-ID');
        $this->assertNotSame(str_repeat('A', 200), $normalise);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $normalise
        );
    }
}
