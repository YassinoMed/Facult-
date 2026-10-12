<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Correlation ID transverse (§21.4) : propage X-Request-ID (client
 * respecté, sinon UUID), contexte de logs, en-tête de réponse.
 * L'API le faisait déjà (ApiExchangeLogger) — ici pour tout le web.
 */
class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $brut = (string) ($request->headers->get('X-Request-ID') ?? '');
        $requestId = preg_match('/^[A-Za-z0-9_-]{1,64}$/', $brut) === 1
            ? $brut
            : (string) Str::uuid();

        $request->headers->set('X-Request-ID', $requestId);
        Log::withContext(['request_id' => $requestId]);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
