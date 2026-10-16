<?php

namespace App\Http\Middleware;

use App\Services\VerrouillageService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verrouillage secondaire après inactivité (LOOP N) : le serveur tient
 * `derniere_activite` en session et impose l'état verrouillé — l'overlay
 * client n'autorise rien à lui seul.
 *
 * Verrouillé : GET → écran de verrouillage (200, sans données) ;
 * non-GET → 423. Exemptés : vérification, déconnexions, MFA.
 * Sans PIN défini : jamais de verrouillage effectif (garde anti-blocage).
 * Mode `passif` (`secondaire.verrou:passif`) pour les sondes périodiques :
 * verrou imposé, activité non touchée.
 *
 * Ordre impératif : TOUJOURS après le groupe `web` (session démarrée
 * avant lecture). Sans session, le middleware traverse (fail-safe).
 */
class SecondaireVerrou
{
    /**
     * Routes exemptées du verrou : noms COMPLETS (préfixe `admin.` du
     * groupe compris). Seuls le déverrouillage et les déconnexions sont
     * exemptés : le réglage du PIN (`admin.verrouillage.code`/`definir`)
     * exige l'état déverrouillé — un verrou actif ne doit pas permettre
     * de modifier le secret qui le lève.
     *
     * @var array<int, string>
     */
    private const EXEMPTS = [
        'admin.verrouillage.verifier',
        'admin.verrouillage.deconnecter',
        'admin.connexion.logout',
        'etudiant.connexion.logout',
        'enseignant.connexion.logout',
        'admin.connexion.defi*',
    ];

    /**
     * Mode `passif` (sondes périodiques : `secondaire.verrou:passif`) :
     * le verrou est imposé mais la sonde ne vaut jamais activité — sinon
     * un polling empêcherait le verrouillage (keep-alive involontaire).
     */
    public function handle(Request $request, Closure $next, string $mode = 'actif'): Response
    {
        // LOOP P (fail-safe) : sans session démarrée (ordonnancement
        // inattendu), rien à verrouiller — traverser plutôt que 500.
        if (! $request->hasSession()) {
            return $next($request);
        }

        $utilisateur = $request->user();

        if ($utilisateur === null) {
            return $next($request);
        }

        /** @var VerrouillageService $verrouillage */
        $verrouillage = app(VerrouillageService::class);

        $passif = $mode === 'passif';

        // B5 : le drapeau de session pilotant l'overlay client est
        // resynchronisé à chaque requête (PIN purgé ou verrouillage
        // globalement désactivé en cours de session ⇒ plus d'overlay).
        $eligible = $verrouillage->estEligible($utilisateur);
        if ($request->hasSession()) {
            $request->session()->put('secondaire_eligible', $eligible);
        }

        if (! $eligible) {
            if (! $passif) {
                $this->toucher($request);
            }

            return $next($request);
        }

        foreach (self::EXEMPTS as $motif) {
            if ($request->routeIs($motif)) {
                return $next($request);
            }
        }

        $verrouille = (bool) $request->session()->get('secondaire_verrouille', false);
        $derniere = $request->session()->get('derniere_activite');

        if (! $verrouille && $derniere !== null) {
            $verrouille = now()->timestamp - (int) $derniere > $verrouillage->delaiSecondes();
        }

        if ($verrouille) {
            $request->session()->put('secondaire_verrouille', true);

            if ($request->isMethod('get') && ! $request->expectsJson()) {
                return response()->view('verrouillage.ecran', [
                    'identite' => $utilisateur->name ?? $utilisateur->email,
                ]);
            }

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Session verrouillée après inactivité.'], 423);
            }

            abort(423, 'Session verrouillée après inactivité.');
        }

        if (! $passif) {
            $this->toucher($request);
        }

        return $next($request);
    }

    private function toucher(Request $request): void
    {
        $derniere = (int) $request->session()->get('derniere_activite', 0);

        if (now()->timestamp - $derniere > 60) {
            $request->session()->put('derniere_activite', now()->timestamp);
        }
    }
}
