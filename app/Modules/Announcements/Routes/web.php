<?php

use App\Modules\Announcements\Controllers\AnnonceController;
use App\Modules\Announcements\Controllers\CampagneController;
use App\Modules\Announcements\Controllers\MesAnnoncesController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module Announcements — annonces ciblées (§39)
|--------------------------------------------------------------------------
| Toujours enregistrées, verrouillées par `module:announcements`.
*/
Route::middleware(['auth', 'admin', 'secondaire.verrou', 'module:announcements'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('annonces', [AnnonceController::class, 'index'])
        ->middleware('can:annonces.view')
        ->name('annonces.index');
    Route::get('annonces/creer', [AnnonceController::class, 'create'])
        ->middleware('can:annonces.create')
        ->name('annonces.create');
    Route::post('annonces', [AnnonceController::class, 'store'])
        ->middleware(['can:annonces.create', 'throttle:20,1'])
        ->name('annonces.store');
    Route::post('annonces/{annonce}/publier', [AnnonceController::class, 'publier'])
        ->middleware(['can:annonces.publish', 'throttle:10,1'])
        ->name('annonces.publier');
    Route::post('annonces/{annonce}/expirer', [AnnonceController::class, 'expirer'])
        ->middleware(['can:annonces.publish', 'throttle:20,1'])
        ->name('annonces.expirer');
    Route::delete('annonces/{annonce}', [AnnonceController::class, 'destroy'])
        ->middleware(['can:annonces.delete', 'throttle:10,1'])
        ->name('annonces.destroy');

    Route::get('campagnes', [CampagneController::class, 'index'])
        ->middleware('can:campagnes.view')
        ->name('campagnes.index');
    Route::post('campagnes', [CampagneController::class, 'store'])
        ->middleware(['can:campagnes.manage', 'throttle:20,1'])
        ->name('campagnes.store');
    Route::post('campagnes/{campagne}/planifier', [CampagneController::class, 'planifier'])
        ->middleware(['can:campagnes.manage', 'throttle:20,1'])
        ->name('campagnes.planifier');
    Route::post('campagnes/{campagne}/envoyer', [CampagneController::class, 'envoyer'])
        ->middleware(['can:campagnes.manage', 'throttle:10,1'])
        ->name('campagnes.envoyer');
});

Route::middleware(['etudiant.auth', 'module:announcements'])->prefix('etudiant')->name('etudiant.')->group(function () {
    Route::get('annonces', [MesAnnoncesController::class, 'index'])->name('annonces.index');
    Route::get('annonces/{annonce}', [MesAnnoncesController::class, 'show'])->name('annonces.show');
});

Route::middleware(['enseignant.auth', 'module:announcements'])->prefix('enseignant')->name('enseignant.')->group(function () {
    Route::get('annonces', [MesAnnoncesController::class, 'indexEnseignant'])->name('annonces.index');
    Route::get('annonces/{annonce}', [MesAnnoncesController::class, 'showEnseignant'])->name('annonces.show');
});
