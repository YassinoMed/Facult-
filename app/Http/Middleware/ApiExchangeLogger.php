<?php

namespace App\Http\Middleware;

use App\Models\IntegrationLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Journal des échanges API (§55) : chaque requête /api/v1 est tracée
 * avec son opération, son statut HTTP, sa durée et un request-id.
 * Aucun payload n'est stocké — uniquement une empreinte SHA-256.
 */
class ApiExchangeLogger
{
    public function handle(Request $request, Closure $next): Response
    {
        // Trace propagation : on respecte un X-Request-ID fourni par le client,
        // sinon on en génère un. Ne JAMAIS écraser l'identifiant entrant (utile
        // à l'anti-replay des webhooks).
        // Borne de sécurité (audit §P2-15) : 1-64 caractères [A-Za-z0-9_-] —
        // toute autre valeur (surdimensionnée, binaire…) est remplacée par un
        // UUID serveur (colonne request_id VARCHAR(64), jamais d'erreur 500).
        $brut = (string) ($request->headers->get('X-Request-ID') ?? '');
        $requestId = preg_match('/^[A-Za-z0-9_-]{1,64}$/', $brut) === 1
            ? $brut
            : (string) Str::uuid();
        $debut = microtime(true);

        $request->headers->set('X-Request-ID', $requestId);

        $response = $next($request);

        // Ne pas journaliser les health-checks
        if (! $request->is('api/v1/health')) {
            $statut = $response->getStatusCode();

            IntegrationLog::tracer(
                integration: 'api',
                direction: 'api',
                operation: $request->route()?->getName() ?? $request->path(),
                status: $statut < 400 ? 'completed' : 'failed',
                httpStatus: $statut,
                durationMs: (int) ((microtime(true) - $debut) * 1000),
                errorCode: $statut >= 400 ? (string) $statut : null,
                requestId: $requestId,
            );
        }

        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
