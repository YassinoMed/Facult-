<?php

use App\Modules\HubIntegration\Http\Controllers\HubIntegrationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module HubIntegration — routes (Lot 18)
|--------------------------------------------------------------------------
| Toujours enregistrées, mais verrouillées par `module:hub-integration`
| (404 si désactivé). Mutations POST uniquement. Permissions RÉUTILISÉES :
| `import.view`, `import_template.manage`, `import.validate`,
| `import.execute` — aucune clé nouvelle. Le journal `integration_logs`
| reste INSERT-ONLY : aucune route ne le modifie.
*/
Route::middleware(['auth', 'admin', 'secondaire.verrou', 'module:hub-integration'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('hub-integration/connecteurs', [HubIntegrationController::class, 'connecteurs'])
        ->middleware('can:import.view')
        ->name('hub-integration.connecteurs');

    Route::post('hub-integration/contrats', [HubIntegrationController::class, 'contratCreer'])
        ->middleware(['can:import_template.manage', 'throttle:30,1'])
        ->name('hub-integration.contrats.creer');

    Route::post('hub-integration/contrats/{id}/activer', [HubIntegrationController::class, 'contratActiver'])
        ->middleware(['can:import_template.manage', 'throttle:30,1'])
        ->whereNumber('id')
        ->name('hub-integration.contrats.activer');

    Route::post('hub-integration/contrats/{id}/deprecier', [HubIntegrationController::class, 'contratDeprecier'])
        ->middleware(['can:import_template.manage', 'throttle:30,1'])
        ->whereNumber('id')
        ->name('hub-integration.contrats.deprecier');

    Route::post('hub-integration/audits', [HubIntegrationController::class, 'auditEnregistrer'])
        ->middleware(['can:import.validate', 'throttle:30,1'])
        ->name('hub-integration.audits.enregistrer');

    Route::get('hub-integration/logs', [HubIntegrationController::class, 'logsIndex'])
        ->middleware('can:import.view')
        ->name('hub-integration.logs.index');

    Route::post('hub-integration/logs/{id}/rejouer', [HubIntegrationController::class, 'rejeuAutoriser'])
        ->middleware(['can:import.execute', 'throttle:20,1'])
        ->whereNumber('id')
        ->name('hub-integration.logs.rejouer');

    Route::post('hub-integration/rejeux/{id}/executer', [HubIntegrationController::class, 'rejeuExecuter'])
        ->middleware(['can:import.execute', 'throttle:20,1'])
        ->whereNumber('id')
        ->name('hub-integration.rejeux.executer');
});
