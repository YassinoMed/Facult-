<?php

namespace App\Http\Middleware;

use App\Services\FeatureFlagService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Garde de déploiement progressif (LOOP 43).
 *
 * Une route protégée par `feature:nom` répond 404 lorsque l'indicateur est
 * inactif pour le contexte de la requête — y compris en appel direct. Le
 * 404 est volontaire : il n'annonce pas l'existence de la fonctionnalité.
 *
 * Contexte transmis, sans PII :
 * - `user_id`      : utilisateur authentifié ;
 * - `faculte_id`   : faculté de l'utilisateur, si elle existe ;
 * - `environnement`: environnement applicatif courant.
 */
class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $flag): Response
    {
        $utilisateur = $request->user();

        // Récupération stricte côté serveur : les paramètres d'URL ou de corps
        // (ex. ?faculte_id=... ou ?programme_id=...) sont formellement ignorés
        // pour empêcher toute falsification d'élévation de périmètre par le client (PHASE D / C).
        $programmeRoute = $request->route('programme') ?? $request->route('programme_id');
        $programmeId = is_object($programmeRoute) && isset($programmeRoute->id)
            ? (int) $programmeRoute->id
            : (is_numeric($programmeRoute) ? (int) $programmeRoute : null);

        $anneeRoute = $request->route('annee') ?? $request->route('annee_id');
        $anneeId = is_object($anneeRoute) && isset($anneeRoute->id)
            ? (int) $anneeRoute->id
            : (is_numeric($anneeRoute) ? (int) $anneeRoute : null);

        $contexte = [
            'user_id' => $utilisateur?->getAuthIdentifier(),
            'faculte_id' => $utilisateur?->faculte_id ?? null,
            'programme_id' => $programmeId,
            'annee_universitaire_id' => $anneeId,
            'environnement' => app()->environment(),
        ];

        abort_unless(app(FeatureFlagService::class)->estActif($flag, $contexte), 404);

        return $next($request);
    }
}
