<?php

use App\Modules\Calendar\Controllers\CalendrierController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module Calendar — calendrier académique (§41)
|--------------------------------------------------------------------------
| Toujours enregistrées, verrouillées par `module:calendar`.
*/
Route::middleware(['auth', 'admin', 'secondaire.verrou', 'module:calendar'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('calendrier', [CalendrierController::class, 'index'])
        ->middleware('can:calendriers.view')
        ->name('calendrier.index');
    Route::post('calendrier', [CalendrierController::class, 'store'])
        ->middleware(['can:calendriers.manage', 'throttle:20,1'])
        ->name('calendrier.store');
    Route::delete('calendrier/{evenement}', [CalendrierController::class, 'destroy'])
        ->middleware(['can:calendriers.manage', 'throttle:10,1'])
        ->name('calendrier.destroy');
});

Route::middleware(['etudiant.auth', 'module:calendar'])->prefix('etudiant')->name('etudiant.')->group(function () {
    Route::get('calendrier', [CalendrierController::class, 'agenda'])->name('calendrier.agenda');
});
