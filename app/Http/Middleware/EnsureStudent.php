<?php

namespace App\Http\Middleware;

use App\Models\Etudiant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
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
 *
 * AUTH-01 (correctif 13/10) : la version du mot de passe est re-vérifiée à
 * chaque requête — une réinitialisation (scolarité) invalide immédiatement
 * les sessions ouvertes, cookie volé compris. Les sessions antérieures à
 * la colonne (version absente → 0) restent valides tant que la version en
 * base vaut 0 : rétrocompatibilité sans déconnexion de masse.
 */
class EnsureStudent
{
    public function handle(Request $request, Closure $next): Response
    {
        $etudiantId = $request->session()->get('etudiant_auth_id');
        $etudiant = $etudiantId === null ? null : Etudiant::find($etudiantId);

        if ($etudiant === null) {
            return $this->expirer($request);
        }

        if ((int) $request->session()->get('etudiant_mdp_version', 0) !== (int) $etudiant->mot_de_passe_version) {
            return $this->expirer($request);
        }

        return $next($request);
    }

    private function expirer(Request $request): RedirectResponse
    {
        $request->session()->forget([
            'etudiant_auth_id',
            'etudiant_auth_prenom',
            'etudiant_auth_nom',
            'etudiant_mdp_version',
        ]);

        return redirect()->route('etudiant.connexion.show')
            ->with('error', 'Votre session a expiré. Veuillez vous reconnecter pour accéder à votre espace.');
    }
}
