<?php

use App\Modules\ParcoursAcademique\Controllers\AmenagementParcoursController;
use App\Modules\ParcoursAcademique\Controllers\FicheParcoursController;
use App\Modules\ParcoursAcademique\Controllers\RegleParcoursController;
use App\Modules\ParcoursAcademique\Controllers\SimulationParcoursController;
use App\Modules\ParcoursAcademique\Controllers\TableauBordParcoursController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module Parcours académique avancé LMD
|--------------------------------------------------------------------------
| Toujours enregistrées, verrouillées par `module:parcours-academique` :
| module désactivé = 404, même en appel direct.
*/
Route::middleware(['auth', 'admin', 'module:parcours-academique'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('parcours', [TableauBordParcoursController::class, 'index'])
        ->middleware('can:parcours.view')
        ->name('parcours.index');

    Route::get('parcours/etudiants/{etudiant}', [FicheParcoursController::class, 'show'])
        ->middleware('can:parcours.view')
        ->name('parcours.fiche');

    Route::get('parcours/comparaison', [FicheParcoursController::class, 'comparer'])
        ->middleware('can:parcours.comparer')
        ->name('parcours.comparer');

    Route::get('parcours/prospective', [TableauBordParcoursController::class, 'prospective'])
        ->middleware(['can:parcours.view', 'throttle:10,1'])
        ->name('parcours.prospective');

    Route::get('parcours/simulations', [SimulationParcoursController::class, 'index'])
        ->middleware('can:parcours.view')
        ->name('parcours.simulations.index');
    Route::post('parcours/simulations', [SimulationParcoursController::class, 'store'])
        ->middleware(['can:parcours.simuler', 'throttle:20,1'])
        ->name('parcours.simulations.store');
    Route::get('parcours/simulations/{simulation}', [SimulationParcoursController::class, 'show'])
        ->middleware('can:parcours.view')
        ->name('parcours.simulations.show');

    Route::get('parcours/{categorie}', [AmenagementParcoursController::class, 'index'])
        ->middleware('can:parcours.view')
        ->whereIn('categorie', ['equivalences', 'dispenses', 'reorientations', 'passerelles', 'risques'])
        ->name('parcours.amenagements.index');
    Route::post('parcours/{categorie}', [AmenagementParcoursController::class, 'store'])
        ->middleware(['can:parcours.gerer', 'throttle:20,1'])
        ->whereIn('categorie', ['equivalences', 'dispenses', 'reorientations', 'passerelles'])
        ->name('parcours.amenagements.store');
    Route::post('parcours/{categorie}/{id}/decider', [AmenagementParcoursController::class, 'decider'])
        ->middleware(['can:parcours.gerer', 'throttle:20,1'])
        ->whereIn('categorie', ['equivalences', 'dispenses', 'reorientations', 'passerelles'])
        ->name('parcours.amenagements.decider');

    Route::post('parcours/plans', [AmenagementParcoursController::class, 'planifier'])
        ->middleware(['can:parcours.gerer', 'throttle:20,1'])
        ->name('parcours.plans.store');
    Route::post('parcours/etudiants/{etudiant}/risque', [AmenagementParcoursController::class, 'risque'])
        ->middleware(['can:parcours.view', 'throttle:10,1'])
        ->name('parcours.risque.store');

    Route::get('parcours-regles', [RegleParcoursController::class, 'index'])
        ->middleware('can:parcours.regles')
        ->name('parcours.regles.index');
    Route::post('parcours-regles', [RegleParcoursController::class, 'store'])
        ->middleware(['can:parcours.regles', 'throttle:20,1'])
        ->name('parcours.regles.store');
});
