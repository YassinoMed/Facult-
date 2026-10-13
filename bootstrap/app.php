<?php

use App\Http\Middleware\ApiExchangeLogger;
use App\Http\Middleware\CheckApiScope;
use App\Http\Middleware\EnsureBackOffice;
use App\Http\Middleware\EnsureFacultyAdmin;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsureStudent;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureTeacher;
use App\Http\Middleware\IdempotencyKey;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\SecondaireVerrou;
use App\Http\Middleware\SecurityHeadersMiddleware;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeadersMiddleware::class);
        $middleware->append(RequestId::class);
        $middleware->alias([
            // Profils (§7-8) : le rôle/statut vient TOUJOURS de la base,
            // jamais d'un paramètre envoyé par le navigateur.
            'etudiant.auth' => EnsureStudent::class, // compat : alias historique
            'student' => EnsureStudent::class,
            'enseignant.auth' => EnsureTeacher::class,
            'admin' => EnsureBackOffice::class,
            'faculty.admin' => EnsureFacultyAdmin::class,
            'super_admin' => EnsureSuperAdmin::class,
            'scope' => CheckApiScope::class,
            'idempotency' => IdempotencyKey::class,
            // Modules (§11) : `module:nom` → 404 si le module est désactivé.
            'module' => EnsureModuleEnabled::class,
            // Verrouillage secondaire après inactivité (LOOP N) : le
            // serveur impose l'état, l'overlay client n'autorise rien.
            'secondaire.verrou' => SecondaireVerrou::class,
        ]);
        // Journal des échanges API : chaque requête /api/* est tracée (§55)
        $middleware->api(prepend: [
            ApiExchangeLogger::class,
        ]);
        // Un invité qui atteint une route protégée du back-office est
        // redirigé vers la connexion scolarité (aucune fuite d'URL interne).
        $middleware->redirectGuestsTo(fn () => route('admin.connexion.show'));
        // Reverse proxies de confiance (audit §P0-5) : '*' ou liste CSV
        // d'IPs via TRUSTED_PROXIES. Vide par défaut = aucun proxy
        // trusté (recommandé en local). En production derrière un
        // TLS terminator / load-balancer, indispensable pour que le
        // rate limiting par IP, le journal d'audit et l'anti-replay
        // webhook voient la VRAIE adresse client et non celle du proxy.
        $middleware->trustProxies(at: env('TRUSTED_PROXIES'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // LOOP 9 — hygiène des journaux : une QueryException loggée brute
        // embarque le SQL + les bindings en clair (CIN, emails, hashes…,
        // constaté le 24/09). On journalise une version assainie (code,
        // table, connexion — jamais SQL ni bindings) et on retourne false
        // pour inhiber le rapport brut par défaut. Le diagnostic reste
        // possible (code SQLSTATE + table + fichier:ligne) sans PII.
        $exceptions->report(function (QueryException $e): ?bool {
            $sql = (string) $e->getSql();
            $table = 'inconnue';
            if (preg_match('/(?:from|into|update)\s+`?([A-Za-z0-9_]+)`?/i', $sql, $m) === 1) {
                $table = $m[1];
            }

            Log::warning('sql.exception_assainie', [
                'code' => $e->getCode(),
                'table' => $table,
                'connexion' => $e->getConnectionName() ?? 'default',
                'fichier' => $e->getFile(),
                'ligne' => $e->getLine(),
                'request_id' => request()?->header('X-Request-ID'),
            ]);

            return false;
        });

        // Enveloppe d'erreur API stable (§20.11) : ADDITIVE — `message`
        // et statuts existants inchangés, `code` + `request_id` ajoutés.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $statut = match (true) {
                $e instanceof ValidationException => 422,
                $e instanceof AuthenticationException => 401,
                $e instanceof AuthorizationException => 403,
                $e instanceof HttpExceptionInterface => $e->getStatusCode(),
                default => 500,
            };

            $code = match (true) {
                $e instanceof ValidationException => 'VALIDATION_ERROR',
                $e instanceof AuthenticationException => 'UNAUTHENTICATED',
                $e instanceof AuthorizationException => 'FORBIDDEN',
                $statut === 404 => 'NOT_FOUND',
                $statut === 429 => 'RATE_LIMITED',
                $statut >= 500 => 'INTERNAL_ERROR',
                default => 'API_ERROR',
            };

            $reponse = [
                'message' => $statut >= 500 && ! config('app.debug')
                    ? 'Erreur interne.'
                    : $e->getMessage(),
                'code' => $code,
                'request_id' => $request->header('X-Request-ID'),
            ];

            if ($e instanceof ValidationException) {
                $reponse['errors'] = $e->errors();
            }

            return response()->json($reponse, $statut);
        });
    })->create();
