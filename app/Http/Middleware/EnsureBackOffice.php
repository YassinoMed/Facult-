<?php

namespace App\Http\Middleware;

use App\Support\Permissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Profil BACK-OFFICE (EnsureBackOffice) : tout utilisateur du registre
 * `users` accédant à /admin doit disposer d'un rôle RBAC valide ET d'un
 * compte actif — tous deux lus en base, jamais depuis le client.
 *
 * Un admin_faculte doit en plus être rattaché à une faculté (faculte_id) :
 * sans rattachement, l'accès est refusé (aucun périmètre = aucun accès).
 */
class EnsureBackOffice
{
    public function handle(Request $request, Closure $next): Response
    {
        $utilisateur = $request->user();

        if ($utilisateur === null) {
            return redirect()->route('admin.connexion.show');
        }

        if (! Permissions::roleValide((string) $utilisateur->role)) {
            abort(403, 'Votre compte ne dispose pas d\'un rôle d\'accès au back-office.');
        }

        if (! $utilisateur->estActif()) {
            abort(403, 'Votre compte est désactivé. Contactez la scolarité centrale.');
        }

        if ($utilisateur->role === 'admin_faculte' && $utilisateur->faculte_id === null) {
            abort(403, 'Aucune faculté n\'est rattachée à votre compte.');
        }

        return $next($request);
    }
}
