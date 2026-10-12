<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applique le principe du moindre privilège sur les tokens API (§48).
 * Usage : ->middleware('scope:students:read')
 */
class CheckApiScope
{
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $utilisateur = $request->user();

        if ($utilisateur === null || $utilisateur->currentAccessToken() === null) {
            return response()->json([
                'message' => 'Authentification API requise.',
            ], 401);
        }

        // Un token doit posséder AU MOINS un des scopes demandés (OU logique)
        foreach ($scopes as $scope) {
            if ($utilisateur->tokenCan($scope)) {
                return $next($request);
            }
        }

        return response()->json([
            // Audit §P3-23 : aucun détail d'autorisation divulgué (ni les
            // scopes requis, ni ceux du token) — 403 générique + code stable.
            'message' => 'Le token utilisé ne dispose pas du scope requis.',
            'error' => 'insufficient_scope',
        ], 403);
    }
}
