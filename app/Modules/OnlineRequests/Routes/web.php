<?php

use App\Modules\OnlineRequests\Controllers\MesDemandesController;
use App\Modules\OnlineRequests\Controllers\OnlineRequestController;
use App\Modules\OnlineRequests\Controllers\RequestTypeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module OnlineRequests — routes
|--------------------------------------------------------------------------
| Toujours enregistrées, verrouillées par `module:online-requests` :
| module désactivé = 404, même en appel direct (serveur uniquement).
*/
Route::middleware(['auth', 'admin', 'secondaire.verrou', 'module:online-requests'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('demandes', [OnlineRequestController::class, 'index'])
        ->middleware('can:demandes.view')
        ->name('demandes.index');
    Route::get('demandes/{demande}', [OnlineRequestController::class, 'show'])
        ->middleware('can:demandes.view')
        ->name('demandes.show');
    Route::patch('demandes/{demande}/recevoir', [OnlineRequestController::class, 'recevoir'])
        ->middleware(['can:demandes.process', 'throttle:20,1'])
        ->name('demandes.recevoir');
    Route::patch('demandes/{demande}/examiner', [OnlineRequestController::class, 'examiner'])
        ->middleware(['can:demandes.process', 'throttle:20,1'])
        ->name('demandes.examiner');
    Route::patch('demandes/{demande}/assigner', [OnlineRequestController::class, 'assigner'])
        ->middleware(['can:demandes.assign', 'throttle:20,1'])
        ->name('demandes.assigner');
    Route::patch('demandes/{demande}/demander-piece', [OnlineRequestController::class, 'demanderPiece'])
        ->middleware(['can:demandes.request_documents', 'throttle:20,1'])
        ->name('demandes.demander-piece');
    Route::patch('demandes/{demande}/approuver', [OnlineRequestController::class, 'approuver'])
        ->middleware(['can:demandes.approve', 'throttle:20,1'])
        ->name('demandes.approuver');
    Route::patch('demandes/{demande}/rejeter', [OnlineRequestController::class, 'rejeter'])
        ->middleware(['can:demandes.reject', 'throttle:20,1'])
        ->name('demandes.rejeter');
    Route::patch('demandes/{demande}/terminer', [OnlineRequestController::class, 'terminer'])
        ->middleware(['can:demandes.process', 'throttle:20,1'])
        ->name('demandes.terminer');
    Route::post('demandes/{demande}/messages', [OnlineRequestController::class, 'commenter'])
        ->middleware(['can:demandes.process', 'throttle:20,1'])
        ->name('demandes.messages.store');
    Route::get('demandes/{demande}/pieces/{piece}/download', [OnlineRequestController::class, 'downloadPiece'])
        ->middleware(['can:demandes.documents.download', 'throttle:20,1'])
        ->name('demandes.pieces.download');
    Route::get('demandes/{demande}/document/download', [OnlineRequestController::class, 'downloadDocument'])
        ->middleware(['can:demandes.pdf', 'throttle:20,1'])
        ->name('demandes.document.download');

    // Délégations de l'autorité de validation (LOT 01) : fenêtre bornée,
    // révocable — gérées par les titulaires de l'affectation.
    Route::post('demandes/delegations', [OnlineRequestController::class, 'deleguer'])
        ->middleware(['can:demandes.assign', 'throttle:10,1'])
        ->name('demandes.delegations.store');
    Route::delete('demandes/delegations/{delegation}', [OnlineRequestController::class, 'revoquerDelegation'])
        ->middleware(['can:demandes.assign', 'throttle:10,1'])
        ->name('demandes.delegations.destroy');

    // Catalogue des services (Phase 9 §41) : configuration globale.
    Route::get('demandes-types', [RequestTypeController::class, 'index'])
        ->middleware('can:configuration.manage')
        ->name('demandes.types.index');
    Route::post('demandes-types', [RequestTypeController::class, 'store'])
        ->middleware(['can:configuration.manage', 'throttle:20,1'])
        ->name('demandes.types.store');
    Route::match(['put', 'patch'], 'demandes-types/{type}', [RequestTypeController::class, 'update'])
        ->middleware(['can:configuration.manage', 'throttle:20,1'])
        ->name('demandes.types.update');
    Route::delete('demandes-types/{type}', [RequestTypeController::class, 'destroy'])
        ->middleware(['can:configuration.manage', 'throttle:10,1'])
        ->name('demandes.types.destroy');
});

Route::middleware(['etudiant.auth', 'module:online-requests'])->prefix('etudiant')->name('etudiant.')->group(function () {
    Route::get('demandes', [MesDemandesController::class, 'index'])->name('demandes.index');
    Route::get('demandes/nouvelle', [MesDemandesController::class, 'create'])->name('demandes.create');
    Route::post('demandes', [MesDemandesController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('demandes.store');
    Route::get('demandes/{demande}', [MesDemandesController::class, 'show'])->name('demandes.show');
    Route::post('demandes/{demande}/soumettre', [MesDemandesController::class, 'submit'])
        ->middleware('throttle:10,1')
        ->name('demandes.submit');
    Route::post('demandes/{demande}/annuler', [MesDemandesController::class, 'cancel'])
        ->middleware('throttle:10,1')
        ->name('demandes.cancel');
    Route::post('demandes/{demande}/pieces', [MesDemandesController::class, 'uploadPiece'])
        ->middleware('throttle:10,1')
        ->name('demandes.pieces.store');
    Route::post('demandes/{demande}/messages', [MesDemandesController::class, 'repondre'])
        ->middleware('throttle:20,1')
        ->name('demandes.messages.repondre');
    Route::get('demandes/{demande}/document/download', [MesDemandesController::class, 'downloadDocument'])
        ->middleware('throttle:20,1')
        ->name('demandes.document.download');
});
