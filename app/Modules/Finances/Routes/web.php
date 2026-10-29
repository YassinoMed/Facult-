<?php

use App\Modules\Finances\Controllers\FinanceController;
use App\Modules\Finances\Controllers\PaiementInscriptionAdminController;
use App\Modules\Finances\Controllers\PaiementInscriptionEtudiantController;
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
    Route::get('finances/remboursements', [FinanceController::class, 'remboursements'])
        ->middleware('can:finances.view')
        ->name('finances.remboursements.index');
    Route::post('finances/paiements/{paiement}/remboursements', [FinanceController::class, 'demanderRemboursement'])
        ->middleware(['can:finances.manage', 'throttle:20,1'])
        ->name('finances.remboursements.store');
    Route::post('finances/remboursements/{remboursement}/traiter', [FinanceController::class, 'traiterRemboursement'])
        ->middleware(['can:finances.manage', 'throttle:20,1'])
        ->name('finances.remboursements.traiter');
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

    /*
     | LOOP-UNIREGISTRE-INSCRIPTIONS-PAIEMENTS-01 : reçus d'inscription.
     | Consultation = finances.view (déjà scopée faculté par la policy) ;
     | décision = finances.receipts.verify (moindre privilège).
     */
    Route::get('finances/paiements-inscriptions', [PaiementInscriptionAdminController::class, 'index'])
        ->middleware('can:finances.view')
        ->name('finances.paiements.index');
    Route::post('finances/paiements-inscriptions/{dossier}/prendre', [PaiementInscriptionAdminController::class, 'prendre'])
        ->middleware(['can:verifier,dossier', 'throttle:30,1'])
        ->name('finances.paiements.prendre');
    Route::post('finances/paiements-inscriptions/{dossier}/valider', [PaiementInscriptionAdminController::class, 'valider'])
        ->middleware(['can:valider,dossier', 'throttle:30,1'])
        ->name('finances.paiements.valider');
    Route::post('finances/paiements-inscriptions/{dossier}/refuser', [PaiementInscriptionAdminController::class, 'refuser'])
        ->middleware(['can:refuser,dossier', 'throttle:30,1'])
        ->name('finances.paiements.refuser');
    Route::get('finances/paiements-inscriptions/{dossier}/recus/{recu}/apercu', [PaiementInscriptionAdminController::class, 'apercu'])
        ->middleware('can:view,dossier')
        ->name('finances.paiements.recu.apercu');
    Route::get('finances/paiements-inscriptions/{dossier}/recus/{recu}/telecharger', [PaiementInscriptionAdminController::class, 'telecharger'])
        ->middleware('can:view,dossier')
        ->name('finances.paiements.recu.telecharger');
    Route::get('finances/paiements-inscriptions/{dossier}', [PaiementInscriptionAdminController::class, 'show'])
        ->middleware('can:view,dossier')
        ->name('finances.paiements.show');
});

Route::middleware(['etudiant.auth', 'module:finances'])->prefix('etudiant')->name('etudiant.')->group(function () {
    Route::get('finances', [FinanceController::class, 'mesFinances'])->name('finances.index');

    // LOOP-UNIREGISTRE-INSCRIPTIONS-PAIEMENTS-01 : parcours étudiant.
    Route::get('paiements', [PaiementInscriptionEtudiantController::class, 'index'])
        ->name('paiements.index');
    Route::get('paiements/inscriptions/{inscription}', [PaiementInscriptionEtudiantController::class, 'show'])
        ->name('paiements.inscription');
    Route::post('paiements/inscriptions/{inscription}/recus', [PaiementInscriptionEtudiantController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('paiements.recus.store');
    Route::get('paiements/inscriptions/{inscription}/recus/{recu}/apercu', [PaiementInscriptionEtudiantController::class, 'apercu'])
        ->name('paiements.recus.apercu');
    Route::get('paiements/inscriptions/{inscription}/recus/{recu}/telecharger', [PaiementInscriptionEtudiantController::class, 'telecharger'])
        ->name('paiements.recus.telecharger');
});
