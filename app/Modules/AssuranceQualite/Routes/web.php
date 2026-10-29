<?php

use App\Modules\AssuranceQualite\Http\Controllers\AssuranceQualiteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module AssuranceQualite — routes (Lot 16)
|--------------------------------------------------------------------------
| Toujours enregistrées, mais verrouillées par `module:assurance-qualite`
| (404 si désactivé). Mutations POST uniquement. Permissions RÉUTILISÉES :
| `qualite.view` (lecture), `qualite.manage` (gestion), `export.create`
| (export) — aucune clé nouvelle. Le socle `admin/qualite` (anomalies)
| existe séparément et n'est PAS touché (frontière anti-fusion).
*/
Route::middleware(['auth', 'admin', 'secondaire.verrou', 'module:assurance-qualite'])->prefix('admin')->name('admin.')->group(function () {
    // Catalogue versionné.
    Route::get('assurance-qualite/referentiels', [AssuranceQualiteController::class, 'referentielsIndex'])
        ->middleware('can:qualite.view')
        ->name('assurance-qualite.referentiels.index');

    Route::post('assurance-qualite/referentiels', [AssuranceQualiteController::class, 'referentielCreer'])
        ->middleware(['can:qualite.manage', 'throttle:30,1,aq-ref'])
        ->name('assurance-qualite.referentiels.creer');

    Route::post('assurance-qualite/referentiels/{id}/versions', [AssuranceQualiteController::class, 'versionCreer'])
        ->middleware(['can:qualite.manage', 'throttle:30,1,aq-ver'])
        ->whereNumber('id')
        ->name('assurance-qualite.versions.creer');

    Route::get('assurance-qualite/versions/{id}', [AssuranceQualiteController::class, 'versionDetail'])
        ->middleware('can:qualite.view')
        ->whereNumber('id')
        ->name('assurance-qualite.versions.detail');

    Route::post('assurance-qualite/versions/{id}/criteres', [AssuranceQualiteController::class, 'critereCreer'])
        ->middleware(['can:qualite.manage', 'throttle:60,1,aq-crit'])
        ->whereNumber('id')
        ->name('assurance-qualite.criteres.creer');

    Route::post('assurance-qualite/versions/{id}/approuver', [AssuranceQualiteController::class, 'versionApprouver'])
        ->middleware(['can:qualite.manage', 'throttle:30,1,aq-ver'])
        ->whereNumber('id')
        ->name('assurance-qualite.versions.approuver');

    Route::post('assurance-qualite/versions/{id}/archiver', [AssuranceQualiteController::class, 'versionArchiver'])
        ->middleware(['can:qualite.manage', 'throttle:30,1,aq-ver'])
        ->whereNumber('id')
        ->name('assurance-qualite.versions.archiver');

    // Chaîne constat → preuve → plan d'action.
    Route::post('assurance-qualite/criteres/{id}/constats', [AssuranceQualiteController::class, 'constatEnregistrer'])
        ->middleware(['can:qualite.manage', 'throttle:60,1,aq-constat'])
        ->whereNumber('id')
        ->name('assurance-qualite.constats.enregistrer');

    Route::post('assurance-qualite/constats/{id}/preuves', [AssuranceQualiteController::class, 'preuveAjouter'])
        ->middleware(['can:qualite.manage', 'throttle:60,1,aq-preuve'])
        ->whereNumber('id')
        ->name('assurance-qualite.preuves.ajouter');

    Route::post('assurance-qualite/constats/{id}/plans-action', [AssuranceQualiteController::class, 'planActionCreer'])
        ->middleware(['can:qualite.manage', 'throttle:60,1,aq-plan'])
        ->whereNumber('id')
        ->name('assurance-qualite.plans.creer');

    Route::post('assurance-qualite/plans-action/{id}/cloturer', [AssuranceQualiteController::class, 'planActionCloturer'])
        ->middleware(['can:qualite.manage', 'throttle:60,1,aq-plan'])
        ->whereNumber('id')
        ->name('assurance-qualite.plans.cloturer');

    // Rapport : workflow d'approbation + export sous permissions.
    Route::post('assurance-qualite/rapports', [AssuranceQualiteController::class, 'rapportSoumettre'])
        ->middleware(['can:qualite.manage', 'throttle:30,1,aq-rap'])
        ->name('assurance-qualite.rapports.soumettre');

    Route::get('assurance-qualite/rapports', [AssuranceQualiteController::class, 'rapportsIndex'])
        ->middleware('can:qualite.view')
        ->name('assurance-qualite.rapports.index');

    Route::get('assurance-qualite/rapports/{id}', [AssuranceQualiteController::class, 'rapportDetail'])
        ->middleware('can:qualite.view')
        ->whereNumber('id')
        ->name('assurance-qualite.rapports.detail');

    Route::post('assurance-qualite/rapports/{id}/approuver', [AssuranceQualiteController::class, 'rapportApprouver'])
        ->middleware(['can:qualite.manage', 'throttle:30,1,aq-rap'])
        ->whereNumber('id')
        ->name('assurance-qualite.rapports.approuver');

    Route::post('assurance-qualite/rapports/{id}/rejeter', [AssuranceQualiteController::class, 'rapportRejeter'])
        ->middleware(['can:qualite.manage', 'throttle:30,1,aq-rap'])
        ->whereNumber('id')
        ->name('assurance-qualite.rapports.rejeter');

    Route::get('assurance-qualite/rapports/{id}/export', [AssuranceQualiteController::class, 'rapportExport'])
        ->middleware(['can:qualite.view', 'can:export.create'])
        ->whereNumber('id')
        ->name('assurance-qualite.rapports.export');
});
