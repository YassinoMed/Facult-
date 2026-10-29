<?php

use App\Modules\GouvernanceConformite\Http\Controllers\GouvernanceConformiteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module GouvernanceConformite — routes (Lot 19)
|--------------------------------------------------------------------------
| Toujours enregistrées, mais verrouillées par `module:gouvernance-conformite`
| (404 si désactivé). Lectures sous `gouvernance.audit` (rôle `auditeur`
| DISTINCT inclus) ; politiques de rétention sous `settings.manage`.
| Aucune suppression de données n'existe dans ce module.
*/
Route::middleware(['auth', 'admin', 'secondaire.verrou', 'module:gouvernance-conformite'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('gouvernance-conformite/acces-sensibles', [GouvernanceConformiteController::class, 'accesSensibles'])
        ->middleware('can:gouvernance.audit')
        ->name('gouvernance-conformite.acces-sensibles');

    Route::get('gouvernance-conformite/permissions-journal', [GouvernanceConformiteController::class, 'changementsPermissions'])
        ->middleware('can:gouvernance.audit')
        ->name('gouvernance-conformite.permissions-journal');

    Route::get('gouvernance-conformite/conformite-retention', [GouvernanceConformiteController::class, 'conformiteRetention'])
        ->middleware('can:gouvernance.audit')
        ->name('gouvernance-conformite.conformite-retention');

    Route::post('gouvernance-conformite/politiques-retention', [GouvernanceConformiteController::class, 'politiqueCreer'])
        ->middleware(['can:settings.manage', 'throttle:30,1'])
        ->name('gouvernance-conformite.politiques.creer');

    Route::post('gouvernance-conformite/politiques-retention/{id}/approuver', [GouvernanceConformiteController::class, 'politiqueApprouver'])
        ->middleware(['can:settings.manage', 'throttle:30,1'])
        ->whereNumber('id')
        ->name('gouvernance-conformite.politiques.approuver');

    Route::get('gouvernance-conformite/journal-acces', [GouvernanceConformiteController::class, 'journalAcces'])
        ->middleware('can:gouvernance.audit')
        ->name('gouvernance-conformite.journal-acces');
});
