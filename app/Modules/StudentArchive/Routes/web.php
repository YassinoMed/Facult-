<?php

use App\Modules\StudentArchive\Controllers\SensitiveRecordController;
use App\Modules\StudentArchive\Controllers\StudentArchiveController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module StudentArchive — dossier étudiant historique (§52)
|--------------------------------------------------------------------------
| Toujours enregistrées, verrouillées par `module:student-archive`.
*/
Route::middleware(['auth', 'admin', 'module:student-archive'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('etudiants/{etudiant}/archive', [StudentArchiveController::class, 'show'])
        ->middleware('can:student_archive.view')
        ->name('etudiants.archive');
    Route::post('etudiants/{etudiant}/archive/sensible', [SensitiveRecordController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('etudiants.archive.sensible.store');
    Route::patch('etudiants/{etudiant}/archive/sensible/{dossier}', [SensitiveRecordController::class, 'update'])
        ->middleware('throttle:20,1')
        ->name('etudiants.archive.sensible.update');
    Route::get('etudiants/{etudiant}/archive/sensible/{dossier}/download', [SensitiveRecordController::class, 'download'])
        ->middleware('throttle:20,1')
        ->name('etudiants.archive.sensible.download');
});
