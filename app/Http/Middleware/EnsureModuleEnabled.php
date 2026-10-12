<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Activation serveur des modules (§11) : une route de module désactivé
 * répond 404, même en appel direct. L'état vient de config/modules.php
 * (backé par env), jamais du navigateur.
 */
class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless((bool) config("modules.{$module}.active", false), 404);

        return $next($request);
    }
}
