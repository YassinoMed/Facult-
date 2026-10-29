<?php

use App\Modules\SimulationTemporelle\Http\Controllers\SimulationTemporelleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module SimulationTemporelle — routes (ST-04)
|--------------------------------------------------------------------------
| Toujours enregistrées, verrouillées par `module:simulation-temporelle`
| (404 si désactivé). Permission RÉUTILISÉE `statistiques.view`,
| throttle préfixé par famille (leçon Lot 16). Mutations POST uniquement.
*/
Route::middleware(['auth', 'admin', 'secondaire.verrou', 'module:simulation-temporelle'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('simulation-temporelle', [SimulationTemporelleController::class, 'index'])
        ->middleware('can:statistiques.view')->name('simulation-temporelle.index');

    Route::get('simulation-temporelle/scenarios', [SimulationTemporelleController::class, 'scenariosIndex'])
        ->middleware('can:statistiques.view')->name('simulation-temporelle.scenarios.index');

    Route::post('simulation-temporelle/scenarios', [SimulationTemporelleController::class, 'scenarioCreer'])
        ->middleware(['can:statistiques.view', 'throttle:20,1,st-def'])->name('simulation-temporelle.scenarios.creer');

    Route::post('simulation-temporelle/scenarios/{id}/executer', [SimulationTemporelleController::class, 'executer'])
        ->middleware(['can:statistiques.view', 'throttle:10,1,st-exec'])
        ->whereNumber('id')->name('simulation-temporelle.executer');

    Route::get('simulation-temporelle/options/niveaux', [SimulationTemporelleController::class, 'optionsNiveaux'])
        ->middleware('can:statistiques.view')->name('simulation-temporelle.options.niveaux');

    Route::get('simulation-temporelle/options/groupes/{niveauId}', [SimulationTemporelleController::class, 'optionsGroupes'])
        ->middleware('can:statistiques.view')->whereNumber('niveauId')
        ->name('simulation-temporelle.options.groupes');

    Route::get('simulation-temporelle/audit-qualite', [SimulationTemporelleController::class, 'auditQualite'])
        ->middleware('can:statistiques.view')->name('simulation-temporelle.audit-qualite');

    Route::post('simulation-temporelle/scenarios/{id}/dupliquer', [SimulationTemporelleController::class, 'scenarioDupliquer'])
        ->middleware(['can:statistiques.view', 'throttle:20,1,st-dup'])
        ->whereNumber('id')->name('simulation-temporelle.scenarios.dupliquer');

    Route::post('simulation-temporelle/scenarios/{id}/nouvelle-version', [SimulationTemporelleController::class, 'scenarioNouvelleVersion'])
        ->middleware(['can:statistiques.view', 'throttle:20,1,st-ver'])
        ->whereNumber('id')->name('simulation-temporelle.scenarios.nouvelle-version');

    Route::get('simulation-temporelle/executions/{id}/export/csv', [SimulationTemporelleController::class, 'exportCsv'])
        ->middleware('can:statistiques.view')->whereNumber('id')
        ->name('simulation-temporelle.executions.export.csv');

    Route::get('simulation-temporelle/executions/{id}/export/pdf', [SimulationTemporelleController::class, 'exportPdf'])
        ->middleware('can:statistiques.view')->whereNumber('id')
        ->name('simulation-temporelle.executions.export.pdf');

    Route::post('simulation-temporelle/executions/{id}/expliquer-ia', [SimulationTemporelleController::class, 'expliquerIa'])
        ->middleware(['can:statistiques.view', 'throttle:20,1,st-ia'])
        ->whereNumber('id')->name('simulation-temporelle.executions.expliquer-ia');

    Route::post('simulation-temporelle/executions/{id}/gouvernance/proposer', [SimulationTemporelleController::class, 'gouvernanceProposer'])
        ->middleware(['can:statistiques.view', 'throttle:15,1,st-gov'])
        ->whereNumber('id')->name('simulation-temporelle.executions.gouvernance.proposer');

    Route::post('simulation-temporelle/executions/{id}/gouvernance/decider', [SimulationTemporelleController::class, 'gouvernanceDecider'])
        ->middleware(['can:statistiques.view', 'throttle:15,1,st-gov'])
        ->whereNumber('id')->name('simulation-temporelle.executions.gouvernance.decider');

    Route::get('simulation-temporelle/executions', [SimulationTemporelleController::class, 'executionsIndex'])
        ->middleware('can:statistiques.view')->name('simulation-temporelle.executions.index');

    Route::get('simulation-temporelle/executions/{id}', [SimulationTemporelleController::class, 'executionDetail'])
        ->middleware('can:statistiques.view')->whereNumber('id')
        ->name('simulation-temporelle.executions.detail');
});
