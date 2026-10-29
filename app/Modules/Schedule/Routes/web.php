<?php

use App\Modules\Schedule\Controllers\MonEmploiDuTempsController;
use App\Modules\Schedule\Controllers\MonEmploiEnseignantController;
use App\Modules\Schedule\Controllers\RemplacementCandidatureController;
use App\Modules\Schedule\Controllers\ScheduleAvailabilityController;
use App\Modules\Schedule\Controllers\ScheduleController;
use App\Modules\Schedule\Controllers\ScheduleExplorerController;
use App\Modules\Schedule\Controllers\ScheduleGenerationController;
use App\Modules\Schedule\Controllers\ScheduleImportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module Schedule — routes
|--------------------------------------------------------------------------
| Toujours enregistrées, verrouillées par `module:schedule` :
| module désactivé = 404, même en appel direct (serveur uniquement).
*/
Route::middleware(['auth', 'admin', 'secondaire.verrou', 'module:schedule'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('emplois-du-temps', [ScheduleController::class, 'index'])
        ->middleware('can:schedules.view')
        ->name('emplois-du-temps.index');
    Route::get('emplois-du-temps/creer', [ScheduleController::class, 'create'])
        ->middleware('can:schedules.view')
        ->name('emplois-du-temps.create');
    Route::post('emplois-du-temps', [ScheduleController::class, 'store'])
        ->middleware(['can:schedules.manage', 'throttle:20,1'])
        ->name('emplois-du-temps.store');
    // Import semestriel CSV/JSON/XML (§10-11) : prévisualisation puis
    // confirmation explicite — jamais d'import direct. Segments fixes
    // AVANT {planning} pour éviter toute capture.
    Route::get('emplois-du-temps/import', [ScheduleImportController::class, 'form'])
        ->middleware('can:schedules.manage')
        ->name('emplois-du-temps.import.form');
    Route::post('emplois-du-temps/import/preview', [ScheduleImportController::class, 'preview'])
        ->middleware(['can:schedules.manage', 'throttle:10,1'])
        ->name('emplois-du-temps.import.preview');
    Route::post('emplois-du-temps/import/confirmer', [ScheduleImportController::class, 'confirmer'])
        ->middleware(['can:schedules.manage', 'throttle:10,1'])
        ->name('emplois-du-temps.import.confirmer');
    Route::post('emplois-du-temps/import/annuler', [ScheduleImportController::class, 'annuler'])
        ->middleware('can:schedules.manage')
        ->name('emplois-du-temps.import.annuler');
    Route::get('emplois-du-temps/imports/{rapport}', [ScheduleImportController::class, 'rapport'])
        ->middleware('can:schedules.view')
        ->name('emplois-du-temps.imports.show');

    // LOT 09 — candidatures de remplacement pédagogique : registre,
    // acceptation pour un créneau (remplacement EDT réutilisé), écart.
    Route::get('remplacement-candidatures', [RemplacementCandidatureController::class, 'index'])
        ->middleware('can:schedules.view')
        ->name('remplacement-candidatures.index');
    Route::post('remplacement-candidatures/{candidature}/accepter', [RemplacementCandidatureController::class, 'accepter'])
        ->middleware(['can:schedules.manage', 'throttle:20,1'])
        ->name('remplacement-candidatures.accepter');
    Route::post('remplacement-candidatures/{candidature}/ecarter', [RemplacementCandidatureController::class, 'ecarter'])
        ->middleware(['can:schedules.manage', 'throttle:20,1'])
        ->name('remplacement-candidatures.ecarter');
    Route::get('emplois-du-temps/explorer', [ScheduleExplorerController::class, 'explorer'])
        ->middleware('can:schedules.view')
        ->name('emplois-du-temps.explorer');
    Route::get('emplois-du-temps/disponibilites', [ScheduleExplorerController::class, 'disponibilites'])
        ->middleware('can:schedules.view')
        ->name('emplois-du-temps.disponibilites');
    Route::get('emplois-du-temps/trouver-creneau', [ScheduleExplorerController::class, 'trouverCreneau'])
        ->middleware('can:schedules.view')
        ->name('emplois-du-temps.trouver-creneau');
    Route::get('emplois-du-temps/charge', [ScheduleExplorerController::class, 'charge'])
        ->middleware('can:schedules.view')
        ->name('emplois-du-temps.charge');
    Route::get('emplois-du-temps/conflits', [ScheduleExplorerController::class, 'conflits'])
        ->middleware('can:schedules.view')
        ->name('emplois-du-temps.conflits');
    Route::get('emplois-du-temps/occupation', [ScheduleExplorerController::class, 'occupation'])
        ->middleware('can:schedules.view')
        ->name('emplois-du-temps.occupation');
    // PHASE A (Smart Timetables) : disponibilités/indisponibilités déclarées
    // par ressource. Segments fixes AVANT {planning} pour éviter la capture.
    Route::get('emplois-du-temps/indisponibilites', [ScheduleAvailabilityController::class, 'index'])
        ->middleware('can:schedules.view')
        ->name('emplois-du-temps.indisponibilites.index');
    Route::get('emplois-du-temps/indisponibilites/creer', [ScheduleAvailabilityController::class, 'create'])
        ->middleware('can:schedules.view')
        ->name('emplois-du-temps.indisponibilites.create');
    Route::post('emplois-du-temps/indisponibilites', [ScheduleAvailabilityController::class, 'store'])
        ->middleware(['can:schedules.manage', 'throttle:20,1'])
        ->name('emplois-du-temps.indisponibilites.store');
    Route::get('emplois-du-temps/indisponibilites/{plage}/modifier', [ScheduleAvailabilityController::class, 'edit'])
        ->middleware('can:schedules.view')
        ->name('emplois-du-temps.indisponibilites.edit');
    Route::patch('emplois-du-temps/indisponibilites/{plage}', [ScheduleAvailabilityController::class, 'update'])
        ->middleware(['can:schedules.manage', 'throttle:20,1'])
        ->name('emplois-du-temps.indisponibilites.update');
    Route::delete('emplois-du-temps/indisponibilites/{plage}', [ScheduleAvailabilityController::class, 'destroy'])
        ->middleware('can:schedules.manage')
        ->name('emplois-du-temps.indisponibilites.destroy');
    // PHASE B (Smart Timetables) : moteur de génération — simulation sans
    // écriture puis génération d'un BROUILLON. Jamais de publication
    // automatique ; segments fixes avant {planning} comme les voisins.
    Route::get('emplois-du-temps/{planning}/generation', [ScheduleGenerationController::class, 'index'])
        ->middleware('can:schedules.view')
        ->name('emplois-du-temps.generation.index');
    Route::post('emplois-du-temps/{planning}/generation/simuler', [ScheduleGenerationController::class, 'simuler'])
        ->middleware(['can:schedules.manage', 'throttle:10,1'])
        ->name('emplois-du-temps.generation.simuler');
    Route::post('emplois-du-temps/{planning}/generation', [ScheduleGenerationController::class, 'generer'])
        ->middleware(['can:schedules.manage', 'throttle:5,1'])
        ->name('emplois-du-temps.generation.generer');
    Route::get('emplois-du-temps/{planning}', [ScheduleController::class, 'show'])
        ->middleware('can:schedules.view')
        ->name('emplois-du-temps.show');
    Route::get('emplois-du-temps/{planning}/tableau', [ScheduleController::class, 'tableau'])
        ->middleware('can:schedules.view')
        ->name('emplois-du-temps.tableau');

    Route::post('emplois-du-temps/{planning}/creneaux', [ScheduleController::class, 'storeSlot'])
        ->middleware(['can:schedules.manage', 'throttle:20,1'])
        ->name('emplois-du-temps.slots.store');
    // Publication / verrouillage / duplication (§12.13-12.19) : contrôle
    // serveur, transaction, historique. Segments fixes avant {planning}.
    Route::post('emplois-du-temps/{planning}/transiter/{cible}', [ScheduleController::class, 'transiter'])
        ->middleware(['can:schedules.manage', 'throttle:10,1'])
        ->whereIn('cible', ['relecture', 'valide', 'brouillon', 'publie', 'verrouille'])
        ->name('emplois-du-temps.transiter');
    Route::post('emplois-du-temps/{planning}/dupliquer', [ScheduleController::class, 'dupliquer'])
        ->middleware(['can:schedules.manage', 'throttle:10,1'])
        ->name('emplois-du-temps.dupliquer');
    Route::get('emplois-du-temps/creneaux/{slot}/modifier', [ScheduleController::class, 'editSlot'])
        ->middleware('can:schedules.view')
        ->name('emplois-du-temps.slots.edit');
    Route::patch('emplois-du-temps/creneaux/{slot}', [ScheduleController::class, 'updateSlot'])
        ->middleware(['can:schedules.manage', 'throttle:20,1'])
        ->name('emplois-du-temps.slots.update');
    Route::delete('emplois-du-temps/creneaux/{slot}', [ScheduleController::class, 'destroySlot'])
        ->middleware(['can:schedules.manage', 'throttle:10,1'])
        ->name('emplois-du-temps.slots.destroy');
    Route::post('emplois-du-temps/creneaux/{slot}/annuler', [ScheduleController::class, 'cancelSlot'])
        ->middleware(['can:schedules.manage', 'throttle:20,1'])
        ->name('emplois-du-temps.slots.cancel');
    Route::post('emplois-du-temps/creneaux/{slot}/restaurer', [ScheduleController::class, 'restoreSlot'])
        ->middleware(['can:schedules.manage', 'throttle:20,1'])
        ->name('emplois-du-temps.slots.restore');
    Route::post('emplois-du-temps/creneaux/{slot}/verifier', [ScheduleController::class, 'verifierDeplacement'])
        ->middleware(['can:schedules.manage', 'throttle:30,1'])
        ->name('emplois-du-temps.slots.verifier');
    Route::patch('emplois-du-temps/creneaux/{slot}/deplacer', [ScheduleController::class, 'deplacer'])
        ->middleware(['can:schedules.manage', 'throttle:20,1'])
        ->name('emplois-du-temps.slots.deplacer');
    Route::post('emplois-du-temps/creneaux/{slot}/exceptions', [ScheduleController::class, 'storeException'])
        ->middleware(['can:schedules.manage', 'throttle:20,1'])
        ->name('emplois-du-temps.slots.exceptions.store');
});

Route::middleware(['etudiant.auth', 'module:schedule'])->prefix('etudiant')->name('etudiant.')->group(function () {
    Route::get('emploi-du-temps', [MonEmploiDuTempsController::class, 'semaine'])->name('emploi-du-temps.semaine');
    Route::get('emploi-du-temps/actualisations', [MonEmploiDuTempsController::class, 'actualisations'])->name('emploi-du-temps.actualisations');
});

Route::middleware(['enseignant.auth', 'module:schedule'])->prefix('enseignant')->name('enseignant.')->group(function () {
    Route::get('emploi-du-temps', [MonEmploiEnseignantController::class, 'semaine'])->name('emploi-du-temps.semaine');
    Route::get('emploi-du-temps.pdf', [MonEmploiEnseignantController::class, 'exporterPdf'])
        ->middleware('throttle:10,1')
        ->name('emploi-du-temps.pdf');
    Route::get('emploi-du-temps/creneaux/{slot}', [MonEmploiEnseignantController::class, 'show'])->name('emploi-du-temps.creneau');
});
