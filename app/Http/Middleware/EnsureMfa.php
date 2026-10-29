<?php

namespace App\Http\Middleware;

use App\Support\MfaPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enrôlement MFA obligatoire (LOOP-SECURITY-REMEDIATION-01).
 *
 * Un compte dont le rôle exige le double facteur mais qui n'a pas encore
 * enrôlé de TOTP ne peut PAS naviguer dans le back-office : il est
 * redirigé vers l'enrôlement (`admin.mfa.configurer`), accessible sans
 * cette garde, ainsi que la déconnexion.
 *
 * Déclenchement strictement serveur : la session doit provenir d'une
 * authentification par mot de passe (marqueur `bo_auth_password` posé
 * par `AuthController` au login) ou porter le marqueur d'enrôlement.
 * Les sessions de test `actingAs` — qui ne traversent aucun login — ne
 * sont pas concernées, sans pour autant affaiblir le contrôle en
 * production.
 *
 * Déploiement : les sessions ouvertes AVANT cette mise en production
 * restent valides le temps de leur expiration — les purger à chaud
 * (voir docs/engineering/LOOP-SECURITY-REMEDIATION-01.md, exigences de
 * production).
 */
class EnsureMfa
{
    public function handle(Request $request, Closure $next): Response
    {
        $utilisateur = $request->user();

        if (! MfaPolicy::exigePour($utilisateur)) {
            return $next($request);
        }

        if ($utilisateur->mfa_active) {
            $request->session()->forget('mfa_enrolement_obligatoire');

            return $next($request);
        }

        $sessionIssueDuLogin = (bool) $request->session()->get('bo_auth_password', false)
            || $request->session()->get('mfa_enrolement_obligatoire') !== null;

        if (! $sessionIssueDuLogin) {
            return $next($request);
        }

        if ($request->routeIs('admin.mfa.*') || $request->routeIs('admin.connexion.logout')) {
            return $next($request);
        }

        return redirect()->route('admin.mfa.configurer')
            ->with('warning', 'Votre rôle exige la double authentification : activez-la pour accéder au back-office.');
    }
}
