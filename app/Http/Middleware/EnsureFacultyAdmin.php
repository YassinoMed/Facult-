<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Profil ADMINISTRATEUR DE FACULTÉ (EnsureFacultyAdmin) : l'utilisateur
 * authentifié DOIT être un admin_faculte rattaché à une faculté et actif.
 *
 * Les données restent ensuite scopées à SA faculté par les policies et
 * requêtes (User::faculteRestreinteId()) — ce middleware ne remplace pas
 * l'isolation, il garantit le profil.
 */
class EnsureFacultyAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $utilisateur = $request->user();

        if ($utilisateur === null || ! $utilisateur->estAdminFaculte() || ! $utilisateur->estActif()) {
            abort(403, 'Cette section est réservée aux administrateurs de faculté.');
        }

        return $next($request);
    }
}
