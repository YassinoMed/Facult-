<?php

use App\Modules\Tasks\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module Tasks — centre de tâches (§9)
|--------------------------------------------------------------------------
| Toujours enregistrées, verrouillées par `module:tasks`.
*/
Route::middleware(['auth', 'admin', 'secondaire.verrou', 'module:tasks'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('taches', [TaskController::class, 'index'])
        ->middleware('can:tasks.view')
        ->name('taches.index');
    Route::get('taches/{tache}', [TaskController::class, 'show'])
        ->middleware('can:tasks.view')
        ->name('taches.show');
    Route::post('taches/{tache}/transiter/{cible}', [TaskController::class, 'transiter'])
        ->middleware(['can:tasks.update', 'throttle:30,1'])
        ->whereIn('cible', ['in_progress', 'waiting', 'completed', 'cancelled'])
        ->name('taches.transiter');
    Route::post('taches/{tache}/assigner', [TaskController::class, 'assigner'])
        ->middleware(['can:tasks.assign', 'throttle:20,1'])
        ->name('taches.assigner');
    Route::post('taches/actions-groupees', [TaskController::class, 'actionGroupee'])
        ->middleware(['can:tasks.update', 'throttle:10,1'])
        ->name('taches.bulk');
});
