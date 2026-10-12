<?php

namespace App\Http\Middleware;

use App\Models\Etudiant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Profil ÉTUDIANT (EnsureStudent) : protège l'Espace Étudiant.
 *
 * Le profil étudiant vit dans la table `etudiants` (authentification par
 * email/CIN + mot de passe, session `etudiant_auth_id`). Il est totalement
 * indépendant du back-office (`users`) : un étudiant n'est JAMAIS considéré
 * comme un administrateur, et réciproquement.
 *
 * La vérification vient EXCLUSIVEMENT de la session serveur + la base de
 * données — jamais d'un paramètre envoyé par le navigateur.
 */
class EnsureStudent
{
    public function handle(Request $request, Closure $next): Response
    {
        $etudiantId = $request->session()->get('etudiant_auth_id');

        if (! $etudiantId || ! Etudiant::where('id', $etudiantId)->exists()) {
            $request->session()->forget(['etudiant_auth_id', 'etudiant_auth_prenom', 'etudiant_auth_nom']);

            return redirect()->route('etudiant.connexion.show')
                ->with('error', 'Votre session a expiré. Veuillez vous reconnecter pour accéder à votre espace.');
        }

        return $next($request);
    }
}
