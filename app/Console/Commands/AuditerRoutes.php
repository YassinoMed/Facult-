<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

/**
 * Audit des routes (§32.3) : FAIL si route /admin sans
 * authentification ; AVERTISSEMENT si sans `can:`/`super_admin` de
 * route (la convention du projet autorise les Gates en contrôleur —
 * cas à revue humaine, pas une faille avérée).
 */
class AuditerRoutes extends Command
{
    protected $signature = 'audit:routes-securite';

    protected $description = 'Vérifie que les routes admin portent authentification + autorisation.';

    private const OUVERTES = [
        'admin/connexion',
        'admin/mot-de-passe/',
    ];

    public function handle(): int
    {
        $critiques = [];
        $revues = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'admin/')) {
                continue;
            }

            foreach (self::OUVERTES as $ouverte) {
                if (str_starts_with($uri, $ouverte)) {
                    continue 2;
                }
            }

            $middlewares = collect($route->gatherMiddleware())->map(fn ($m) => is_string($m) ? $m : (is_object($m) ? $m::class : ''))->all();
            $texte = implode('|', $middlewares);

            $authentifiee = str_contains($texte, 'auth') || str_contains($texte, 'super_admin') || str_contains($texte, 'EnsureBackOffice');
            $gardeRoute = str_contains($texte, 'can:') || str_contains($texte, 'super_admin') || str_contains($texte, 'EnsureSuperAdmin');

            $etiquette = implode(',', $route->methods())." /{$uri}";

            if (! $authentifiee) {
                $critiques[] = $etiquette;
            } elseif (! $gardeRoute) {
                $revues[] = $etiquette;
            }
        }

        foreach ($revues as $ligne) {
            $this->warn('REVUE (Gate contrôleur attendu) : '.$ligne);
        }

        if ($critiques !== []) {
            foreach ($critiques as $ligne) {
                $this->error('SANS AUTH : '.$ligne);
            }

            return self::FAILURE;
        }

        $this->info('Routes admin : authentification OK ('.count($revues).' à revue humaine).');

        return self::SUCCESS;
    }
}
