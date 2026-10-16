<?php

use App\Modules\Sentinel\Controllers\SentinelController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module Sentinel — routes (§11)
|--------------------------------------------------------------------------
| Toujours enregistrées, mais verrouillées par `module:sentinel` :
| module désactivé = 404, même en appel direct.
*/
Route::middleware(['auth', 'admin', 'secondaire.verrou', 'module:sentinel'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('sentinelle', [SentinelController::class, 'index'])
        ->middleware('can:sentinel.view')
        ->name('sentinel.index');
    Route::post('sentinelle/incidents/{incident}/transiter', [SentinelController::class, 'transiter'])
        ->middleware(['can:sentinel.manage', 'throttle:20,1'])
        ->whereNumber('incident')
        ->name('sentinel.incidents.transiter');
    Route::get('sentinelle/incidents/{incident}', [SentinelController::class, 'show'])
        ->middleware('can:sentinel.view')
        ->whereNumber('incident')
        ->name('sentinel.incidents.show');
    Route::get('sentinelle/regles', [SentinelController::class, 'regles'])
        ->middleware('can:sentinel.view')
        ->name('sentinel.regles');
    Route::post('sentinelle/regles/{code}/bascule', [SentinelController::class, 'basculer'])
        ->middleware(['can:sentinel.manage', 'throttle:20,1'])
        ->where('code', '[A-Z0-9_]+')
        ->name('sentinel.regles.basculer');
    Route::post('sentinelle/executer', [SentinelController::class, 'executer'])
        ->middleware(['can:sentinel.manage', 'throttle:5,1'])
        ->name('sentinel.executer');
});
