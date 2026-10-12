<?php

namespace Tests\Unit;

use App\Services\Io\WebhookVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Audit §P2-13 / §P2-15 : le vérifieur HMAC contrôle la signature AVANT de
 * consommer l'anti-replay, et borne le format du X-Request-ID — testé au
 * niveau service (le middleware HTTP normalise déjà les valeurs).
 */
class WebhookVerifierTest extends TestCase
{
    private const SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['integrations.webhook_secret' => self::SECRET]);
        Cache::flush();
    }

    private function requete(string $corps, ?string $signature, ?string $requestId, ?int $timestamp = null): Request
    {
        return Request::create(
            '/api/v1/webhooks/external-system',
            'POST',
            [],
            [],
            [],
            [
                'HTTP_X_SIGNATURE' => (string) $signature,
                'HTTP_X_TIMESTAMP' => (string) ($timestamp ?? now()->getTimestamp()),
                'HTTP_X_REQUEST_ID' => (string) $requestId,
            ],
            $corps
        );
    }

    private function signatureDe(string $corps): string
    {
        return base64_encode(hash_hmac('sha256', $corps, self::SECRET, true));
    }

    public function test_request_id_malforme_rejete_sans_toucher_le_cache(): void
    {
        $verifieur = new WebhookVerifier;
        $corps = '{"event":"ping"}';

        $resultat = $verifieur->verifier(
            $this->requete($corps, $this->signatureDe($corps), str_repeat('A', 200))
        );

        $this->assertFalse($resultat['valide']);
        $this->assertSame('', $resultat['request_id']);
        // Aucune clé arbitraire ne pollue le cache anti-replay.
        $this->assertFalse(Cache::has('webhook:replay:'.str_repeat('A', 200)));
    }

    public function test_signature_invalide_ne_consomme_pas_l_anti_replay(): void
    {
        $verifieur = new WebhookVerifier;
        $corps = '{"event":"ping"}';
        $requestId = (string) Str::uuid();

        $echec = $verifieur->verifier($this->requete($corps, base64_encode('falsifie'), $requestId));
        $this->assertFalse($echec['valide']);

        $succes = $verifieur->verifier($this->requete($corps, $this->signatureDe($corps), $requestId));
        $this->assertTrue($succes['valide']);
        $this->assertSame($requestId, $succes['request_id']);
    }

    public function test_rejeu_legitime_toujours_refuse_apres_succes(): void
    {
        $verifieur = new WebhookVerifier;
        $corps = '{"event":"ping"}';
        $requestId = (string) Str::uuid();

        $this->assertTrue($verifieur->verifier($this->requete($corps, $this->signatureDe($corps), $requestId))['valide']);

        $rejeu = $verifieur->verifier($this->requete($corps, $this->signatureDe($corps), $requestId));
        $this->assertFalse($rejeu['valide']);
    }
}
