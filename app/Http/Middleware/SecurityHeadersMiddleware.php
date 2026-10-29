<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeadersMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // LOOP-SECURITY-REMEDIATION-01 : masque la version de PHP
        // (`X-Powered-By`) — empreinte inutile pour un attaquant.
        // Double ceinture : le ini `expose_php=Off` est exigé en
        // production (docs/engineering/LOOP-SECURITY-PRODUCTION.md).
        $response->headers->remove('X-Powered-By');
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // Audit §P3-23 : X-XSS-Protection supprimé (obsolète, only legacy IE —
        // la protection XSS repose sur l'échappement Blade + la CSP).
        /*
         * LOOP-01 — le jeton de réinitialisation circule dans le chemin de
         * l'URL. Sur ces pages uniquement : `no-referrer` empêche le
         * navigateur de transmettre l'URL complète (donc le jeton) via
         * l'en-tête `Referer` (journaux, ressources), et `no-store`
         * interdit la mise en cache (poste partagé, historique, proxy).
         */
        $pageReinitialisation = $request->routeIs(
            'etudiant.mot-de-passe.reinitialiser',
            'etudiant.mot-de-passe.enregistrer',
            'enseignant.mot-de-passe.reinitialiser',
            'enseignant.mot-de-passe.enregistrer',
        );

        $response->headers->set(
            'Referrer-Policy',
            $pageReinitialisation ? 'no-referrer' : 'strict-origin-when-cross-origin',
        );

        if ($pageReinitialisation) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        }
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        // Isolation cross-origin (audit §P3-23) : COOP + CORP same-origin.
        // COEP require-corp volontairement absent (casserait fonts.googleapis).
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');

        /*
         * HSTS (audit §P0-5) — émis UNIQUEMENT sur une connexion chiffrée :
         * un en-tête reçu en HTTP plain verrouillerait un domaine mal
         * configuré. Requiert TLS + TRUSTED_PROXIES en production.
         */
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        /*
         * Content-Security-Policy — compatible avec la stack actuelle :
         * - Vite sert les assets depuis 'self' ;
         * - Alpine.js évalue ses expressions (x-data, @click…) via
         *   new Function : 'unsafe-eval' est donc requis dans script-src ;
         * - les styles inline de Tailwind/Alpine exigent 'unsafe-inline' ;
         * - Inter (en-tête des vues) provient de fonts.googleapis/gstatic.
         *
         * LOOP-SECURITY-REMEDIATION-01 (durcissement) :
         * - base-uri 'none' : aucun <base> injectable (détournement d'URL
         *   relative) — l'application n'utilise pas <base> ;
         * - media-src 'none' : aucun média embarqué dans les vues ;
         * - upgrade-insecure-requests, uniquement sur HTTPS : les
         *   ressources relatives ne peuvent plus fuiter en clair.
         *
         * La suppression totale de 'unsafe-eval'/'unsafe-inline'
         * nécessiterait un build Alpine en mode CSP (voir
         * docs/engineering/LOOP-SECURITY-PRODUCTION.md).
         */
        $viteDirectives = '';
        $viteWs = '';
        if (app()->isLocal()) {
            // Pas de littéral IPv6 : la grammaire CSP n'accepte pas
            // `http://[::1]:5173` (source invalide, ignorée + erreur console).
            $viteDirectives = ' http://localhost:5173 http://127.0.0.1:5173';
            $viteWs = ' ws://localhost:5173 ws://127.0.0.1:5173';
        }

        $directives = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'".$viteDirectives,
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com".$viteDirectives,
            "font-src 'self' https://fonts.gstatic.com data:".$viteDirectives,
            "img-src 'self' data:".$viteDirectives,
            "media-src 'none'",
            "connect-src 'self'".$viteDirectives.$viteWs,
            "object-src 'none'",
            "base-uri 'none'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ];

        if ($request->secure()) {
            $directives[] = 'upgrade-insecure-requests';
        }

        $response->headers->set('Content-Security-Policy', implode('; ', $directives));

        return $response;
    }
}
