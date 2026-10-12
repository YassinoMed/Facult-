<?php

namespace App\Services\Io;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Vérification HMAC-SHA256 des webhooks externes (§53-54) :
 * - X-Signature  : base64(HMAC-SHA256(corps brut, secret)) ;
 * - X-Timestamp  : fenêtre de tolérance configurable (anti-requêtes anciennes) ;
 * - X-Request-ID : anti-replay (24 h en cache).
 *
 * NE JAMAIS accepter un webhook uniquement parce que l'URL est connue.
 */
class WebhookVerifier
{
    /**
     * @return array{valide: bool, raison: string|null, request_id: string}
     */
    public function verifier(Request $request): array
    {
        $secret = (string) config('integrations.webhook_secret');

        if ($secret === '') {
            return ['valide' => false, 'raison' => 'Aucun secret webhook configuré (INTEGRATION_WEBHOOK_SECRET).', 'request_id' => ''];
        }

        $signature = (string) $request->header('X-Signature', '');
        $timestamp = (string) $request->header('X-Timestamp', '');
        $requestId = (string) $request->header('X-Request-ID', '');

        if ($signature === '' || $timestamp === '' || $requestId === '') {
            return ['valide' => false, 'raison' => 'En-têtes X-Signature, X-Timestamp et X-Request-ID obligatoires.', 'request_id' => $requestId];
        }

        // Format du request-id borné (audit §P2-15) : 1-64 caractères
        // [A-Za-z0-9_-] — évite les clés de cache arbitraires et les
        // valeurs incompatibles avec request_id VARCHAR(64).
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $requestId) !== 1) {
            return ['valide' => false, 'raison' => 'X-Request-ID invalide (attendu : 1 à 64 caractères alphanumériques, tirets ou soulignés).', 'request_id' => ''];
        }

        // Fenêtre temporelle : refus des requêtes trop anciennes
        if (! ctype_digit($timestamp) || abs(now()->getTimestamp() - (int) $timestamp) > (int) config('integrations.webhook_tolerance')) {
            return ['valide' => false, 'raison' => 'Timestamp expiré ou invalide (anti-replay).', 'request_id' => $requestId];
        }

        // Signature HMAC-SHA256 du corps brut — VÉRIFIÉE AVANT de consommer
        // l'anti-replay (audit §P2-13) : une signature invalide ne doit
        // jamais « brûler » un X-Request-ID légitime pendant 24 h, et les
        // requêtes non authentifiées ne remplissent plus le cache.
        $attendue = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        if (! hash_equals($attendue, $signature)) {
            return ['valide' => false, 'raison' => 'Signature HMAC invalide.', 'request_id' => $requestId];
        }

        // Anti-replay strict : un request-id ne peut servir deux fois sur 24 h
        if (! Cache::add('webhook:replay:'.$requestId, true, now()->addDay())) {
            return ['valide' => false, 'raison' => 'Requête rejouée (X-Request-ID déjà traitée).', 'request_id' => $requestId];
        }

        return ['valide' => true, 'raison' => null, 'request_id' => $requestId];
    }
}
