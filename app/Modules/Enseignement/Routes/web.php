<?php

use App\Modules\Enseignement\Controllers\EnseignementController;
use App\Modules\Enseignement\Controllers\EvaluationController;
use App\Modules\Enseignement\Controllers\MesRessourcesController;
use App\Modules\Enseignement\Controllers\RessourcesEtudiantController;
use App\Modules\Enseignement\Controllers\ServiceController;
use App\Modules\Enseignement\Controllers\SousGroupeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module Enseignement — routes (§44, §46-47)
|--------------------------------------------------------------------------
| Dashboard, table, fiches, transitions, affectations, évaluation,
| services, qualité. Verrou `module:enseignement`.
*/
Route::middleware(['auth', 'admin', 'secondaire.verrou', 'module:enseignement'])->prefix('admin')->name('admin.')->group(function () {
    Route::prefix('enseignements')->name('enseignements.')->group(function () {
        Route::get('tableau-bord', [EnseignementController::class, 'tableauBord'])
            ->middleware('can:enseignements.view')->name('tableau-bord');
        Route::get('/', [EnseignementController::class, 'index'])
            ->middleware('can:enseignements.view')->name('index');
        Route::get('creer', [EnseignementController::class, 'create'])
            ->middleware('can:enseignements.manage')->name('create');
        Route::post('/', [EnseignementController::class, 'store'])
            ->middleware(['can:enseignements.manage', 'throttle:20,1'])->name('store');
        Route::get('services', [ServiceController::class, 'services'])
            ->middleware('can:enseignements.view')->name('services');
        Route::post('seuils', [ServiceController::class, 'seuilStore'])
            ->middleware(['can:enseignements.manage', 'throttle:20,1'])->name('seuils.store');
        Route::delete('seuils/{seuil}', [ServiceController::class, 'seuilDestroy'])
            ->middleware(['can:enseignements.manage', 'throttle:10,1'])->name('seuils.destroy');
        Route::get('types', [ServiceController::class, 'types'])
            ->middleware('can:enseignements.view')->name('types');
        Route::post('types', [ServiceController::class, 'typeStore'])
            ->middleware(['can:enseignements.manage', 'throttle:20,1'])->name('types.store');
        Route::match(['put', 'patch'], 'types/{type}', [ServiceController::class, 'typeUpdate'])
            ->middleware(['can:enseignements.manage', 'throttle:20,1'])->name('types.update');
        Route::delete('types/{type}', [ServiceController::class, 'typeDestroy'])
            ->middleware(['can:enseignements.manage', 'throttle:10,1'])->name('types.destroy');
        Route::get('qualite', [ServiceController::class, 'qualite'])
            ->middleware('can:enseignements.view')->name('qualite');
        Route::get('{enseignement}', [EnseignementController::class, 'show'])
            ->middleware('can:enseignements.view')->whereNumber('enseignement')->name('show');
        Route::get('{enseignement}/modifier', [EnseignementController::class, 'edit'])
            ->middleware('can:enseignements.manage')->whereNumber('enseignement')->name('edit');
        Route::match(['put', 'patch'], '{enseignement}', [EnseignementController::class, 'update'])
            ->middleware(['can:enseignements.manage', 'throttle:20,1'])->whereNumber('enseignement')->name('update');
        Route::delete('{enseignement}', [EnseignementController::class, 'destroy'])
            ->middleware(['can:enseignements.manage', 'throttle:10,1'])->whereNumber('enseignement')->name('destroy');
        Route::patch('{enseignement}/transition', [EnseignementController::class, 'transition'])
            ->middleware(['can:enseignements.manage', 'throttle:20,1'])->whereNumber('enseignement')->name('transition');
        Route::post('{enseignement}/affectations', [EnseignementController::class, 'affecter'])
            ->middleware(['can:enseignements.manage', 'throttle:20,1'])->whereNumber('enseignement')->name('affectations.store');
        Route::post('{enseignement}/groupes', [EnseignementController::class, 'rattacherGroupe'])
            ->middleware(['can:enseignements.manage', 'throttle:20,1'])->whereNumber('enseignement')->name('groupes.store');
        Route::get('evaluation/{matiere}', [EvaluationController::class, 'edit'])
            ->middleware('can:enseignements.view')->name('evaluation.edit');
        Route::put('evaluation/{matiere}/composantes', [EvaluationController::class, 'composantes'])
            ->middleware(['can:enseignements.manage', 'throttle:20,1'])->name('evaluation.composantes');
        Route::patch('evaluation/{matiere}/responsable', [EvaluationController::class, 'responsable'])
            ->middleware(['can:enseignements.manage', 'throttle:20,1'])->name('evaluation.responsable');
        Route::delete('evaluation/composantes/{composante}', [EvaluationController::class, 'detruireComposante'])
            ->middleware(['can:enseignements.manage', 'throttle:20,1'])->name('evaluation.composantes.destroy');
    });

    Route::delete('affectations-enseignement/{affectation}', [EnseignementController::class, 'desaffecter'])
        ->middleware(['can:enseignements.manage', 'throttle:20,1'])->name('affectations-enseignement.destroy');
    Route::delete('enseignement-groupes/{lien}', [EnseignementController::class, 'detacherGroupe'])
        ->middleware(['can:enseignements.manage', 'throttle:20,1'])->name('enseignement-groupes.destroy');

    Route::get('groupes/{groupe}/sous-groupes', [SousGroupeController::class, 'index'])
        ->middleware('can:groupes.view')->name('sous-groupes.index');
    Route::post('groupes/{groupe}/sous-groupes', [SousGroupeController::class, 'store'])
        ->middleware(['can:groupes.manage', 'throttle:20,1'])->name('sous-groupes.store');
    Route::delete('sous-groupes/{sousGroupe}', [SousGroupeController::class, 'destroy'])
        ->middleware(['can:groupes.manage', 'throttle:10,1'])->name('sous-groupes.destroy');
});

/*
|--------------------------------------------------------------------------
| Espace Enseignant — supports de cours de MES ECUE (LOOP 06)
|--------------------------------------------------------------------------
| Session `enseignant_auth_id` + `module:enseignement` (404 si inactif).
| Publication limitée aux ECUE affectés ; stockage privé ; cycle serveur.
*/
Route::middleware(['enseignant.auth', 'module:enseignement'])->prefix('enseignant')->name('enseignant.')->group(function () {
    Route::get('ressources', [MesRessourcesController::class, 'index'])->name('ressources.index');
    Route::get('ressources/creer', [MesRessourcesController::class, 'create'])->name('ressources.create');
    Route::post('ressources', [MesRessourcesController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('ressources.store');
    Route::get('ressources/{ressource}', [MesRessourcesController::class, 'show'])->name('ressources.show');
    Route::post('ressources/{ressource}/publier', [MesRessourcesController::class, 'publier'])
        ->middleware('throttle:10,1')
        ->name('ressources.publier');
    Route::post('ressources/{ressource}/archiver', [MesRessourcesController::class, 'archiver'])
        ->middleware('throttle:10,1')
        ->name('ressources.archiver');
    Route::post('ressources/{ressource}/retirer', [MesRessourcesController::class, 'retirer'])
        ->middleware('throttle:10,1')
        ->name('ressources.retirer');
    Route::delete('ressources/{ressource}', [MesRessourcesController::class, 'destroy'])
        ->middleware('throttle:10,1')
        ->name('ressources.destroy');
    Route::get('ressources/{ressource}/telecharger', [MesRessourcesController::class, 'telecharger'])
        ->middleware('throttle:20,1')
        ->name('ressources.telecharger');
});

/*
|--------------------------------------------------------------------------
| Espace Étudiant — supports publiés pour MES matières et MON groupe
| (LOOP 14, volet supports). Visibilité scopée, téléchargement privé.
*/
Route::middleware(['etudiant.auth', 'module:enseignement'])->prefix('etudiant')->name('etudiant.')->group(function () {
    Route::get('ressources', [RessourcesEtudiantController::class, 'index'])->name('ressources.index');
    Route::get('ressources/{ressource}/telecharger', [RessourcesEtudiantController::class, 'telecharger'])
        ->middleware('throttle:20,1')
        ->name('ressources.telecharger');
});
