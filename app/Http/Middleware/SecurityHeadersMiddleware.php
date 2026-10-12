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

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // Audit §P3-23 : X-XSS-Protection supprimé (obsolète, only legacy IE —
        // la protection XSS repose sur l'échappement Blade + la CSP).
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
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
         * En production, resserrer 'unsafe-eval' nécessiterait un build
         * Alpine en mode CSP (voir docs/SECURITY.md).
         */
        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com data:",
            "img-src 'self' data:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ]));

        return $response;
    }
}
