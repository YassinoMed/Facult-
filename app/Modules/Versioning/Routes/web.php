<?php

use App\Modules\Versioning\Controllers\DeploymentController;
use App\Modules\Versioning\Controllers\ReleaseController;
use App\Modules\Versioning\Controllers\VersioningController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module Versioning — Routes d'administration interne
|--------------------------------------------------------------------------
| Protégées par auth + admin + module:versioning.
| Toutes les actions sensibles sont de surcroît gardées par Policy.
*/
Route::middleware(['auth', 'admin', 'module:versioning'])
    ->prefix('admin/versioning')
    ->name('admin.versioning.')
    ->group(function () {

        // ── Tableau de bord & Vues transversales ─────────────────────
        Route::get('/', [VersioningController::class, 'index'])
            ->middleware('can:versioning.view')
            ->name('index');

        Route::get('drift', [VersioningController::class, 'drift'])
            ->middleware('can:versioning.view')
            ->name('drift');

        Route::get('compatibilite', [VersioningController::class, 'compatibilite'])
            ->middleware('can:versioning.view')
            ->name('compatibilite');

        Route::get('manifest/{version}', [VersioningController::class, 'manifest'])
            ->middleware('can:versioning.view')
            ->name('manifest');

        Route::get('audit', [VersioningController::class, 'audit'])
            ->middleware('can:versioning.view')
            ->name('audit');

        Route::get('comparer', [ReleaseController::class, 'comparer'])
            ->middleware('can:versioning.view')
            ->name('releases.comparer');

        // ── Releases ─────────────────────────────────────────────────
        Route::get('releases', [ReleaseController::class, 'index'])
            ->middleware('can:versioning.view')
            ->name('releases.index');

        Route::get('releases/creer', [ReleaseController::class, 'create'])
            ->middleware('can:versioning.create')
            ->name('releases.create');

        Route::post('releases', [ReleaseController::class, 'store'])
            ->middleware(['can:versioning.create', 'throttle:15,1'])
            ->name('releases.store');

        Route::get('releases/{release}', [ReleaseController::class, 'show'])
            ->middleware('can:versioning.view')
            ->name('releases.show');

        Route::post('releases/{release}/transition', [ReleaseController::class, 'transition'])
            ->middleware(['throttle:30,1'])
            ->name('releases.transition');

        // ── Déploiements & Rollbacks ─────────────────────────────────
        Route::get('deploiements', [DeploymentController::class, 'index'])
            ->middleware('can:versioning.view')
            ->name('deployments.index');

        Route::get('deploiements/{deployment}', [DeploymentController::class, 'show'])
            ->middleware('can:versioning.view')
            ->name('deployments.show');

        Route::get('releases/{release}/deployer', [DeploymentController::class, 'create'])
            ->middleware('can:versioning.deploy')
            ->name('deployments.create');

        Route::post('releases/{release}/deployer', [DeploymentController::class, 'store'])
            ->middleware(['can:versioning.deploy', 'throttle:5,1'])
            ->name('deployments.store');

        Route::get('releases/{release}/rollback', [DeploymentController::class, 'rollbackPlan'])
            ->middleware('can:versioning.rollback')
            ->name('rollback.plan');

        Route::post('releases/{release}/rollback', [DeploymentController::class, 'rollback'])
            ->middleware(['can:versioning.rollback', 'throttle:5,1'])
            ->name('rollback.execute');
    });
