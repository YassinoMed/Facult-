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
 */
class SecondaireVerrou
{
    /**
     * @var array<int, string>
     */
    private const EXEMPTS = [
        'admin.verrouillage.*',
        'admin.connexion.logout',
        'etudiant.connexion.logout',
        'enseignant.connexion.logout',
        'admin.connexion.defi*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $utilisateur = $request->user();

        if ($utilisateur === null) {
            return $next($request);
        }

        /** @var VerrouillageService $verrouillage */
        $verrouillage = app(VerrouillageService::class);

        if (! $verrouillage->estEligible($utilisateur)) {
            $this->toucher($request);

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

        $this->toucher($request);

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
