<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/**
 * Résolveur canonique de destination pour le logo UniRegistre (§LOT 1).
 *
 * Détermine la route cible selon l'état d'authentification effectif, le rôle,
 * les permissions réelles et le contexte de navigation :
 * - Visiteur non authentifié : route d'accueil publique (`inscription.create` ou `accueil`).
 * - Étudiant connecté (`session('etudiant_auth_id')`) : `etudiant.dashboard`.
 * - Enseignant connecté (`session('enseignant_auth_id')`) : `enseignant.dashboard`.
 * - Back-office connecté (`User` authentifié) :
 *     - Utilisateur actif avec rôle RBAC valide (`super_admin`, `admin_scolarite`,
 *       `agent_scolarite`, `consultation`, `admin_faculte` avec faculté) : `admin.dashboard`.
 *     - Utilisateur inactif ou sans rôle d'accès back-office : redirection de repli
 *       sûre (pas de 403).
 * - Multi-rôles : résolution contextuelle selon l'espace actif
 *   (`admin.*`, `enseignant.*`, `etudiant.*`), avec repli hiérarchique maîtrisé.
 */
final class NavigationDestination
{
    public static function resoudre(?Request $request = null): string
    {
        $request ??= request();

        // 1. Détection du contexte multi-rôles selon la route active
        if ($request && $request->routeIs('enseignant.*') && self::estEnseignantConnecte($request)) {
            return self::routeExisteOuRepli('enseignant.dashboard');
        }

        if ($request && $request->routeIs('etudiant.*') && self::estEtudiantConnecte($request)) {
            return self::routeExisteOuRepli('etudiant.dashboard');
        }

        if ($request && $request->routeIs('admin.*') && self::estBackOfficeAutorise()) {
            return self::routeExisteOuRepli('admin.dashboard');
        }

        // 2. Priorité standard selon l'authentification effective
        if (self::estBackOfficeAutorise()) {
            return self::routeExisteOuRepli('admin.dashboard');
        }

        if (self::estEnseignantConnecte($request)) {
            return self::routeExisteOuRepli('enseignant.dashboard');
        }

        if (self::estEtudiantConnecte($request)) {
            return self::routeExisteOuRepli('etudiant.dashboard');
        }

        // 3. Utilisateur authentifié via User mais sans rôle back-office valide
        if (Auth::check()) {
            /** @var User $user */
            $user = Auth::user();

            // Si le compte User a un profil Enseignant actif rattaché
            if ($user && $user->teacher && $user->teacher->actif) {
                return self::routeExisteOuRepli('enseignant.dashboard');
            }
        }

        // 4. Visiteur ou utilisateur sans destination spécifique
        return self::destinationPublique();
    }

    public static function estBackOfficeAutorise(?User $user = null): bool
    {
        $user ??= Auth::user();

        if ($user === null) {
            return false;
        }

        if (! $user->estActif()) {
            return false;
        }

        if (! Permissions::roleValide((string) $user->role)) {
            return false;
        }

        if ($user->role === 'admin_faculte' && $user->faculte_id === null) {
            return false;
        }

        return true;
    }

    public static function estEnseignantConnecte(?Request $request = null): bool
    {
        $request ??= request();

        return (bool) ($request && $request->session() ? $request->session()->get('enseignant_auth_id') : session('enseignant_auth_id'));
    }

    public static function estEtudiantConnecte(?Request $request = null): bool
    {
        $request ??= request();

        return (bool) ($request && $request->session() ? $request->session()->get('etudiant_auth_id') : session('etudiant_auth_id'));
    }

    public static function destinationPublique(): string
    {
        if (Route::has('inscription.create')) {
            return route('inscription.create');
        }

        if (Route::has('accueil')) {
            return route('accueil');
        }

        return '/';
    }

    private static function routeExisteOuRepli(string $nomRoute): string
    {
        if (Route::has($nomRoute)) {
            return route($nomRoute);
        }

        return self::destinationPublique();
    }
}
