<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hôte de confiance + origine canonique (LOOP-SECURITY-REMEDIATION-01).
 *
 * Toute requête dont l'en-tête `Host` ne figure pas dans
 * `security.trusted_hosts` est rejetée en 400 : impossible d'empoisonner
 * les URL absolues générées (réinitialisation de mot de passe, webhooks,
 * liens de notification) via un Host forgé. En complément, lorsque
 * `security.force_canonical_url` est actif (production), les URL
 * absolues utilisent systématiquement l'origine APP_URL.
 *
 * Contrainte : ce middleware s'exécute tôt (prepend global) mais après
 * la résolution des proxies de confiance (`trustProxies`), afin de
 * comparer l'hôte réel vu par l'application.
 */
class EnsureTrustedHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $hote = mb_strtolower((string) $request->getHost());

        if (! $this->hoteAutorise($hote)) {
            abort(400, 'Adresse hôte non autorisée.');
        }

        if ((bool) config('security.force_canonical_url', false)) {
            $canonique = (string) config('app.url');

            if ($canonique !== '') {
                URL::forceRootUrl($canonique);

                $schema = parse_url($canonique, PHP_URL_SCHEME);
                if (is_string($schema) && $schema !== '') {
                    URL::forceScheme($schema);
                }
            }
        }

        return $next($request);
    }

    private function hoteAutorise(string $hote): bool
    {
        if ($hote === '') {
            return false;
        }

        foreach ((array) config('security.trusted_hosts', []) as $motif) {
            $motif = mb_strtolower(trim((string) $motif));

            if ($motif === '') {
                continue;
            }

            if ($motif === $hote) {
                return true;
            }

            // `*.exemple.com` : sous-domaines uniquement (jamais le
            // domaine nu, pour éviter qu'un motif large n'ouvre la porte
            // à l'apex lui-même si non explicitement listé).
            if (str_starts_with($motif, '*.')) {
                $suffixe = substr($motif, 1); // ".exemple.com"

                if (str_ends_with($hote, $suffixe) && strlen($hote) > strlen($suffixe)) {
                    return true;
                }
            }
        }

        return false;
    }
}
