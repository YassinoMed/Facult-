<?php

namespace App\Http\Middleware;

use App\Modules\Schedule\Models\Teacher;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Profil ENSEIGNANT (EnsureTeacher) : protège l'Espace Enseignant.
 *
 * Le profil enseignant vit dans la table `teachers` (authentification par
 * email/matricule + mot de passe, session `enseignant_auth_id`). Il est
 * totalement indépendant du back-office (`users`) : un enseignant n'est
 * JAMAIS considéré comme un administrateur, et réciproquement (miroir
 * EnsureStudent).
 *
 * La vérification vient EXCLUSIVEMENT de la session serveur + la base de
 * données — jamais d'un paramètre envoyé par le navigateur.
 */
class EnsureTeacher
{
    public function handle(Request $request, Closure $next): Response
    {
        $enseignantId = $request->session()->get('enseignant_auth_id');
        $enseignant = $enseignantId ? Teacher::where('id', $enseignantId)->where('actif', true)->first() : null;

        if (! $enseignant) {
            $request->session()->forget(['enseignant_auth_id', 'enseignant_auth_prenom', 'enseignant_auth_nom']);

            return redirect()->route('enseignant.connexion.show')
                ->with('error', 'Votre session a expiré. Veuillez vous reconnecter pour accéder à votre espace.');
        }

        $request->attributes->set('enseignant', $enseignant);

        return $next($request);
    }
}
