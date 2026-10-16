<?php

use App\Modules\Finances\Controllers\FinanceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module Finances — gestion financière universitaire (§23)
|--------------------------------------------------------------------------
| Toujours enregistrées, verrouillées par `module:finances`.
| Montants en centimes, jamais de floats. Aucune donnée bancaire.
*/
Route::middleware(['auth', 'admin', 'secondaire.verrou', 'module:finances'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('finances', [FinanceController::class, 'dashboard'])
        ->middleware('can:finances.view')
        ->name('finances.index');
    Route::get('finances/factures', [FinanceController::class, 'factures'])
        ->middleware('can:finances.view')
        ->name('finances.factures.index');
    Route::get('finances/factures/{facture}', [FinanceController::class, 'show'])
        ->middleware('can:finances.view')
        ->name('finances.factures.show');
    Route::post('inscriptions/{inscription}/facturer', [FinanceController::class, 'emettre'])
        ->middleware(['can:finances.manage', 'throttle:10,1'])
        ->name('finances.emettre');
    Route::post('finances/factures/{facture}/payer', [FinanceController::class, 'payer'])
        ->middleware(['can:finances.manage', 'throttle:20,1'])
        ->name('finances.payer');
    Route::post('finances/paiements/{paiement}/confirmer', [FinanceController::class, 'confirmer'])
        ->middleware(['can:finances.confirm', 'throttle:20,1'])
        ->name('finances.confirmer');
    Route::get('finances/paiements/{paiement}/recu', [FinanceController::class, 'recu'])
        ->middleware(['can:finances.confirm', 'throttle:10,1'])
        ->name('finances.recu');
    Route::get('finances/tarifs', [FinanceController::class, 'tarifs'])
        ->middleware('can:finances.manage')
        ->name('finances.tarifs.index');
    Route::post('finances/tarifs', [FinanceController::class, 'storeTarif'])
        ->middleware(['can:finances.manage', 'throttle:20,1'])
        ->name('finances.tarifs.store');
});

Route::middleware(['etudiant.auth', 'module:finances'])->prefix('etudiant')->name('etudiant.')->group(function () {
    Route::get('finances', [FinanceController::class, 'mesFinances'])->name('finances.index');
});
