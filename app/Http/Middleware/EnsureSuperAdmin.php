<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Profil SUPER ADMINISTRATEUR (EnsureSuperAdmin) : administration globale
 * (gestion des facultés, des administrateurs, des permissions, audits).
 * Le rôle est lu en base de données — jamais depuis le client.
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $utilisateur = $request->user();

        if ($utilisateur === null || ! $utilisateur->estSuperAdmin() || ! $utilisateur->estActif()) {
            abort(403, 'Cette section est réservée au Super Administrateur.');
        }

        return $next($request);
    }
}
