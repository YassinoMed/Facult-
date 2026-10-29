<?php

use App\Modules\Alumni\Controllers\ConsentementAlumniController;
use App\Modules\Alumni\Controllers\DiplomeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module Alumni — routes (Lot 17)
|--------------------------------------------------------------------------
| Toujours enregistrées, mais verrouillées par `module:alumni` (404 si
| désactivé).
|
| Surface back-office : registre officiel (lecture + import), annuaire,
| agrégats. Surface publique : consentement par lien SIGNÉ et temporaire.
| Aucune route employeur, aucune route de dossier académique.
*/

Route::middleware(['module:alumni', 'signed', 'throttle:20,1'])
    ->prefix('alumni/consentement')
    ->name('alumni.consentement.')
    ->group(function () {
        Route::get('{diplome}', [ConsentementAlumniController::class, 'show'])
            ->name('show');

        Route::post('{diplome}', [ConsentementAlumniController::class, 'store'])
            ->name('store');

        Route::post('{diplome}/retrait', [ConsentementAlumniController::class, 'retirer'])
            ->name('retrait');
    });

Route::middleware(['auth', 'admin', 'secondaire.verrou', 'module:alumni'])
    ->prefix('admin/alumni')
    ->name('admin.alumni.')
    ->group(function () {
        Route::get('diplomes', [DiplomeController::class, 'index'])
            ->middleware('can:alumni.view')
            ->name('diplomes.index');

        Route::post('diplomes/import', [DiplomeController::class, 'importer'])
            ->middleware(['can:alumni.import', 'throttle:5,1'])
            ->name('diplomes.import');

        Route::get('imports', [DiplomeController::class, 'imports'])
            ->middleware('can:alumni.import')
            ->name('imports.index');

        Route::get('imports/{import}', [DiplomeController::class, 'showImport'])
            ->middleware('can:alumni.import')
            ->name('imports.show');

        Route::post('diplomes/{diplome}/lien-consentement', [DiplomeController::class, 'lienConsentement'])
            ->middleware(['can:alumni.manage', 'throttle:10,1'])
            ->name('diplomes.lien');

        Route::get('annuaire', [DiplomeController::class, 'annuaire'])
            ->middleware('can:alumni.view')
            ->name('annuaire');

        Route::get('agregats', [DiplomeController::class, 'agregats'])
            ->middleware('can:alumni.view')
            ->name('agregats');
    });
