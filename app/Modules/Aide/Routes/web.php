<?php

use App\Modules\Aide\Controllers\AideController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module Aide — centre d'aide utilisateur
|--------------------------------------------------------------------------
| Rendu public (candidats inclus) de la KB « docs/utilisateur/ » :
| lecture seule, contenu statique du dépôt, aucune donnée métier.
| Activation : config/modules.php <- MODULE_AIDE (actif par défaut ;
| `module:aide` → 404 si désactivé). Throttle généreux : contenu caché.
*/
Route::middleware(['module:aide', 'throttle:30,1'])
    ->prefix('aide')
    ->name('aide.')
    ->group(function () {
        Route::get('/', [AideController::class, 'index'])->name('index');

        Route::get('/{slug}', [AideController::class, 'montrer'])
            ->where('slug', '[a-z0-9-]+')
            ->name('page');
    });
