<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotence des écritures API (§11.12) : un client qui rejoue un
 * POST avec le même `Idempotency-Key` reçoit la réponse originelle
 * sans réexécuter l'opération métier (double clic, retry, timeout).
 * Clé scopée par token (jamais de collision inter-clients), TTL 24h,
 * sans PII (statut + corps de réponse uniquement).
 * Usage : ->middleware('idempotency')
 */
class IdempotencyKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $cle = trim((string) $request->header('Idempotency-Key', ''));

        if ($cle === '' || ! preg_match('/^[A-Za-z0-9_-]{8,64}$/', $cle)) {
            return $next($request);
        }

        $tokenId = $request->user()?->currentAccessToken()?->id ?? 'session';
        $cache = "idempotence:{$tokenId}:{$cle}";

        if (Cache::has($cache)) {
            $rejeu = Cache::get($cache);

            return response()->json($rejeu['corps'], $rejeu['statut'], ['X-Idempotent-Replayed' => 'true']);
        }

        /** @var Response $reponse */
        $reponse = $next($request);

        // Seules les réponses JSON sont rejouables (jamais de
        // binaire/fichier : un téléchargement ne se rejoue pas).
        if ($reponse->getStatusCode() < 500
            && str_contains((string) $reponse->headers->get('Content-Type', ''), 'application/json')) {
            $contenu = $reponse->getContent();

            if ($contenu !== false) {
                Cache::put($cache, [
                    'statut' => $reponse->getStatusCode(),
                    'corps' => json_decode($contenu, true) ?? [],
                ], 86400);
            }
        }

        $reponse->headers->set('X-Idempotent-Replayed', 'false');

        return $reponse;
    }
}
