<?php

use App\Modules\PreventionDecrochage\Http\Controllers\PreventionDecrochageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module PreventionDecrochage — routes (Lot 15)
|--------------------------------------------------------------------------
| Toujours enregistrées, mais verrouillées par `module:prevention-decrochage`
| (404 si désactivé). Mutations POST uniquement. Aucune écriture dans les
| données officielles (notes, décisions, statuts d'inscription).
*/
Route::middleware(['auth', 'admin', 'secondaire.verrou', 'module:prevention-decrochage'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('prevention-decrochage/regles', [PreventionDecrochageController::class, 'reglesIndex'])
        ->middleware('can:decrochage.view')
        ->name('prevention-decrochage.regles.index');

    Route::post('prevention-decrochage/regles', [PreventionDecrochageController::class, 'reglesCreer'])
        ->middleware(['can:decrochage.regles', 'throttle:30,1'])
        ->name('prevention-decrochage.regles.creer');

    Route::post('prevention-decrochage/regles/{id}/approuver', [PreventionDecrochageController::class, 'regleApprouver'])
        ->middleware(['can:decrochage.regles', 'throttle:30,1'])
        ->whereNumber('id')
        ->name('prevention-decrochage.regles.approuver');

    Route::post('prevention-decrochage/regles/{id}/desactiver', [PreventionDecrochageController::class, 'regleDesactiver'])
        ->middleware(['can:decrochage.regles', 'throttle:30,1'])
        ->whereNumber('id')
        ->name('prevention-decrochage.regles.desactiver');

    Route::post('prevention-decrochage/detecter', [PreventionDecrochageController::class, 'detecter'])
        ->middleware(['can:decrochage.traiter', 'throttle:20,1'])
        ->name('prevention-decrochage.detecter');

    Route::get('prevention-decrochage/alertes', [PreventionDecrochageController::class, 'alertesIndex'])
        ->middleware('can:decrochage.view')
        ->name('prevention-decrochage.alertes.index');

    Route::post('prevention-decrochage/alertes/{id}/examiner', [PreventionDecrochageController::class, 'examiner'])
        ->middleware(['can:decrochage.traiter', 'throttle:30,1'])
        ->whereNumber('id')
        ->name('prevention-decrochage.alertes.examiner');

    Route::post('prevention-decrochage/alertes/{id}/cloturer', [PreventionDecrochageController::class, 'cloturer'])
        ->middleware(['can:decrochage.traiter', 'throttle:30,1'])
        ->whereNumber('id')
        ->name('prevention-decrochage.alertes.cloturer');

    Route::post('prevention-decrochage/alertes/{id}/suivis', [PreventionDecrochageController::class, 'ajouterSuivi'])
        ->middleware(['can:decrochage.traiter', 'throttle:30,1'])
        ->whereNumber('id')
        ->name('prevention-decrochage.alertes.suivis.creer');

    Route::get('prevention-decrochage/alertes/{id}/suivis', [PreventionDecrochageController::class, 'suivisIndex'])
        ->middleware('can:decrochage.view')
        ->whereNumber('id')
        ->name('prevention-decrochage.alertes.suivis.index');
});
