<?php

use App\Modules\CentreServicesIt\Controllers\CategorieItController;
use App\Modules\CentreServicesIt\Controllers\TicketItController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module CentreServicesIt — routes (Lot 11)
|--------------------------------------------------------------------------
| Toujours enregistrées, mais verrouillées par `module:centre-services-it`
| (404 si désactivé). Le module est une vue métier AU-DESSUS de `tasks` :
| aucune transition n'est écrite hors de TaskService.
|
| Surface : création idempotente, consultation, transition, affectation,
| escalade, statistiques et catalogue. Pas de pièce jointe ni de purge :
| décisions non arbitrées (D11-5 / D11-7), hors périmètre revendiqué.
*/
Route::middleware(['auth', 'admin', 'secondaire.verrou', 'module:centre-services-it'])
    ->prefix('admin/centre-services-it')
    ->name('admin.centre-services-it.')
    ->group(function () {
        Route::get('tickets', [TicketItController::class, 'index'])
            ->middleware('can:it.view')
            ->name('tickets.index');

        Route::post('tickets', [TicketItController::class, 'store'])
            ->middleware(['can:it.create', 'throttle:30,1'])
            ->name('tickets.store');

        Route::get('tickets/{ticket}', [TicketItController::class, 'show'])
            ->middleware('can:it.view')
            ->name('tickets.show');

        Route::post('tickets/{ticket}/transition', [TicketItController::class, 'transition'])
            ->middleware(['can:it.manage', 'throttle:60,1'])
            ->name('tickets.transition');

        Route::post('tickets/{ticket}/assigner', [TicketItController::class, 'assigner'])
            ->middleware(['can:it.manage', 'throttle:30,1'])
            ->name('tickets.assigner');

        Route::post('tickets/{ticket}/escalader', [TicketItController::class, 'escalader'])
            ->middleware(['can:it.manage', 'throttle:30,1'])
            ->name('tickets.escalader');

        Route::get('statistiques', [TicketItController::class, 'statistiques'])
            ->middleware('can:it.view')
            ->name('statistiques');

        Route::get('categories', [CategorieItController::class, 'index'])
            ->middleware('can:it.view')
            ->name('categories.index');

        Route::post('categories', [CategorieItController::class, 'store'])
            ->middleware(['can:it.categories', 'throttle:20,1'])
            ->name('categories.store');

        Route::patch('categories/{categorie}', [CategorieItController::class, 'update'])
            ->middleware(['can:it.categories', 'throttle:20,1'])
            ->name('categories.update');
    });
