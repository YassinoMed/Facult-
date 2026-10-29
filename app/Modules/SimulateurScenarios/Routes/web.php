<?php

use App\Modules\SimulateurScenarios\Http\Controllers\ScenariosController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module SimulateurScenarios — routes (Lot 14)
|--------------------------------------------------------------------------
| Toujours enregistrées, mais verrouillées par `module:simulateur-scenarios` :
| module désactivé = 404, même en appel direct (activation serveur
| uniquement via MODULE_SIMULATEUR_SCENARIOS). Lecture seule : exécuter
| et comparer un scénario ne modifie aucune donnée officielle.
*/
Route::middleware(['auth', 'admin', 'secondaire.verrou', 'module:simulateur-scenarios'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('simulateur-scenarios', [ScenariosController::class, 'index'])
        ->middleware('can:statistiques.view')
        ->name('simulateur-scenarios.index');

    Route::post('simulateur-scenarios/executer', [ScenariosController::class, 'executer'])
        ->middleware('can:statistiques.view')
        ->name('simulateur-scenarios.executer');

    Route::post('simulateur-scenarios/comparer', [ScenariosController::class, 'comparer'])
        ->middleware('can:statistiques.view')
        ->name('simulateur-scenarios.comparer');
});
