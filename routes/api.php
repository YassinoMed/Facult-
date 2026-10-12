<?php

use App\Http\Controllers\Api\V1\AssignmentApiController;
use App\Http\Controllers\Api\V1\DataExchangeApiController;
use App\Http\Controllers\Api\V1\ExportApiController;
use App\Http\Controllers\Api\V1\ImportApiController;
use App\Http\Controllers\Api\V1\ImportTemplateApiController;
use App\Http\Controllers\Api\V1\ReadyController;
use App\Http\Controllers\Api\V1\ReferentielApiController;
use App\Http\Controllers\Api\V1\RegistrationApiController;
use App\Http\Controllers\Api\V1\ScheduleImportApiController;
use App\Http\Controllers\Api\V1\StudentApiController;
use App\Http\Controllers\Api\V1\WebhookController;
use App\Modules\ParcoursAcademique\Controllers\ParcoursApiController;
use App\Modules\Versioning\Controllers\Api\VersioningApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — couche d'interopérabilité (§44)
|--------------------------------------------------------------------------
| Versionnée : /api/v1/* — une future v2 ne cassera jamais la v1 (§64).
| Chaque route porte : scope Sanctum (moindre privilège §48) + rate
| limiting dédié (§49). Les webhooks sont protégés par HMAC (§53).
*/

Route::prefix('v1')->name('api.v1.')->group(function () {

    // Sonde d'intégration (sans token)
    Route::get('health', fn () => response()->json([
        'status' => 'ok',
        'version' => 'v1',
        'time' => now()->toIso8601String(),
    ]))->name('health');

    // Readiness (sans token, pour LB/K8s) : dépendances réelles, sans PII.
    Route::get('ready', ReadyController::class)
        ->middleware('throttle:api')
        ->name('ready');

    // Sonde de versionnage système (sans token, sans PII ni secrets)
    Route::get('version', [VersioningApiController::class, 'version'])
        ->middleware('throttle:api')
        ->name('version');

    /*
    | Webhooks externes — pas de token Sanctum : authentification par
    | signature HMAC-SHA256 + timestamp + anti-replay (§53-54).
    */
    Route::post('webhooks/external-system', [WebhookController::class, 'receive'])
        ->middleware('throttle:api-webhooks')
        ->name('webhooks.receive');

    /*
    | Endpoints authentifiés par token Sanctum à scopes restreints.
    */
    Route::middleware('auth:sanctum')->group(function () {

        // ── Étudiants (lecture) ──
        Route::get('students', [StudentApiController::class, 'index'])
            ->middleware('scope:students:read', 'throttle:api')
            ->name('students.index');
        Route::get('students/{student}', [StudentApiController::class, 'show'])
            ->whereNumber('student')
            ->middleware('scope:students:read', 'throttle:api')
            ->name('students.show');

        // ── Dossier académique / notes / documents (lecture restreinte) ──
        Route::get('students/{student}/academic-record', [StudentApiController::class, 'academicRecord'])
            ->whereNumber('student')
            ->middleware('scope:archives:read', 'throttle:api')
            ->name('students.record');

        Route::get('students/{student}/grades', [StudentApiController::class, 'grades'])
            ->whereNumber('student')
            ->middleware('scope:grades:read', 'throttle:api')
            ->name('students.grades');

        Route::get('students/{student}/documents', [StudentApiController::class, 'documents'])
            ->whereNumber('student')
            ->middleware('scope:documents:read', 'throttle:api')
            ->name('students.documents');

        // ── Résultats académiques (Phase 6 §38, lecture moteur) ──
        Route::get('students/{student}/results', [StudentApiController::class, 'results'])
            ->whereNumber('student')
            ->middleware('scope:grades:read', 'throttle:api')
            ->name('students.results');

        // ── Timeline académique (Phase 8 §9, registre d'archive) ──
        Route::get('students/{student}/timeline', [StudentApiController::class, 'timeline'])
            ->whereNumber('student')
            ->middleware('scope:archives:read', 'throttle:api')
            ->name('students.timeline');

        // ── Vérification intelligente du dossier étudiant (§21) ──
        Route::get('students/{student}/verification', [StudentApiController::class, 'verification'])
            ->whereNumber('student')
            ->middleware('scope:students:read', 'throttle:api')
            ->name('students.verification');

        Route::post('students/{student}/verification', [StudentApiController::class, 'runVerification'])
            ->whereNumber('student')
            ->middleware('scope:students:read', 'throttle:api')
            ->name('students.verification.run');

        Route::get('students/{student}/verification/anomalies', [StudentApiController::class, 'anomalies'])
            ->whereNumber('student')
            ->middleware('scope:students:read', 'throttle:api')
            ->name('students.verification.anomalies');

        // ── Parcours académique (lecture seule, scopes archives:read) ──
        Route::get('students/{student}/parcours', [ParcoursApiController::class, 'parcours'])
            ->whereNumber('student')
            ->middleware('scope:archives:read', 'throttle:api')
            ->name('students.parcours');
        Route::get('students/{student}/parcours-credits', [ParcoursApiController::class, 'credits'])
            ->whereNumber('student')
            ->middleware('scope:archives:read', 'throttle:api')
            ->name('students.parcours-credits');
        Route::get('students/{student}/parcours-risques', [ParcoursApiController::class, 'risques'])
            ->whereNumber('student')
            ->middleware('scope:archives:read', 'throttle:api')
            ->name('students.parcours-risques');
        Route::get('students/{student}/parcours-simulations', [ParcoursApiController::class, 'simulations'])
            ->whereNumber('student')
            ->middleware('scope:archives:read', 'throttle:api')
            ->name('students.parcours-simulations');

        // ── Référentiel LMD (lecture seule, scope dédié) ──
        Route::get('referentiel/programmes', [ReferentielApiController::class, 'programmes'])
            ->middleware('scope:referentiel:read', 'throttle:api')
            ->name('referentiel.programmes');
        Route::get('referentiel/niveaux', [ReferentielApiController::class, 'niveaux'])
            ->middleware('scope:referentiel:read', 'throttle:api')
            ->name('referentiel.niveaux');
        Route::get('referentiel/semestres', [ReferentielApiController::class, 'semestres'])
            ->middleware('scope:referentiel:read', 'throttle:api')
            ->name('referentiel.semestres');
        Route::get('referentiel/ues', [ReferentielApiController::class, 'ues'])
            ->middleware('scope:referentiel:read', 'throttle:api')
            ->name('referentiel.ues');
        Route::get('referentiel/matieres', [ReferentielApiController::class, 'matieres'])
            ->middleware('scope:referentiel:read', 'throttle:api')
            ->name('referentiel.matieres');
        Route::get('referentiel/maquettes', [ReferentielApiController::class, 'maquettes'])
            ->middleware('scope:referentiel:read', 'throttle:api')
            ->name('referentiel.maquettes');

        // ── Inscriptions (Phase 3, lecture seule, scope archives:read) ──
        Route::get('registrations', [RegistrationApiController::class, 'index'])
            ->middleware('scope:archives:read', 'throttle:api')
            ->name('registrations.index');
        Route::get('registrations/{registration}', [RegistrationApiController::class, 'show'])
            ->whereNumber('registration')
            ->middleware('scope:archives:read', 'throttle:api')
            ->name('registrations.show');

        // ── Réinscription (contexte + progression en lecture, écriture
        // scopée + throttle + idempotence HTTP) ──
        Route::get('students/{student}/reenrollment-context', [RegistrationApiController::class, 'reenrollmentContext'])
            ->whereNumber('student')
            ->middleware('scope:archives:read', 'throttle:api')
            ->name('students.reenrollment-context');
        Route::get('students/{student}/academic-progression', [RegistrationApiController::class, 'progression'])
            ->whereNumber('student')
            ->middleware('scope:archives:read', 'throttle:api')
            ->name('students.academic-progression');
        // ── État période d'inscription (même source page/POST, scope archives:read) ──
        Route::get('students/{student}/registration-period', [RegistrationApiController::class, 'periodeStatut'])
            ->whereNumber('student')
            ->middleware('scope:archives:read', 'throttle:api')
            ->name('students.registration-period');
        Route::post('registrations/reenroll/{student}', [RegistrationApiController::class, 'reenroll'])
            ->whereNumber('student')
            ->middleware('scope:registrations:write', 'throttle:api-imports', 'idempotency')
            ->name('registrations.reenroll');

        // ── Modèles d'importation (§114) ──
        // Audit §P2-12 : exiger `imports:create` (moindre privilège) — un
        // token cantonné à de la lecture ne doit pas récupérer les modèles.
        Route::get('import-templates', [ImportTemplateApiController::class, 'index'])
            ->middleware('scope:imports:create', 'throttle:api')
            ->name('templates.index');
        Route::get('import-templates/{type}', [ImportTemplateApiController::class, 'show'])
            ->middleware('scope:imports:create', 'throttle:api')
            ->name('templates.show');
        Route::get('import-templates/{type}/download', [ImportTemplateApiController::class, 'download'])
            ->middleware('scope:imports:create', 'throttle:api-exports')
            ->name('templates.download');

        // ── Imports (deux phases : prévisualisation → confirmation) ──
        Route::post('imports', [ImportApiController::class, 'store'])
            ->middleware('scope:imports:create', 'throttle:api-imports', 'idempotency')
            ->name('imports.store');
        Route::post('imports/{report}/confirm', [ImportApiController::class, 'confirm'])
            ->whereNumber('report')
            ->middleware('scope:imports:create', 'throttle:api-imports')
            ->name('imports.confirm');
        Route::get('imports/{report}', [ImportApiController::class, 'show'])
            ->whereNumber('report')
            ->middleware('scope:imports:create', 'throttle:api')
            ->name('imports.show');

        // ── Import emplois du temps (stateless : fichier re-transmis,
        // dry-run intégré, scope dédié + throttle imports) ──
        Route::post('schedules/import/preview', [ScheduleImportApiController::class, 'preview'])
            ->middleware('scope:schedules:import', 'throttle:api-imports')
            ->name('schedules.import.preview');
        Route::post('schedules/import/confirm', [ScheduleImportApiController::class, 'confirmer'])
            ->middleware('scope:schedules:import', 'throttle:api-imports')
            ->name('schedules.import.confirm');

        // ── Import/export unifié par entité (stateless, dry-run, scope) ──
        Route::post('data/{entite}/import/preview', [DataExchangeApiController::class, 'preview'])
            ->middleware('scope:data:exchange', 'throttle:api-imports')
            ->name('data.import.preview');
        Route::post('data/{entite}/import/confirm', [DataExchangeApiController::class, 'confirmer'])
            ->middleware('scope:data:exchange', 'throttle:api-imports')
            ->name('data.import.confirm');
        Route::get('data/{entite}/export/{format}', [DataExchangeApiController::class, 'exporter'])
            ->middleware('scope:data:exchange', 'throttle:api-exports')
            ->name('data.export');

        // ── Affectations automatiques (simulation sans écriture,
        // confirmation par groupe et par tranches) ──
        Route::get('assignments/simulations', [AssignmentApiController::class, 'index'])
            ->middleware('scope:assignments:read', 'throttle:api')
            ->name('assignments.index');
        Route::post('assignments/simulate', [AssignmentApiController::class, 'simuler'])
            ->middleware('scope:assignments:write', 'throttle:api-imports')
            ->name('assignments.simulate');
        Route::get('assignments/simulations/{simulation}', [AssignmentApiController::class, 'show'])
            ->whereNumber('simulation')
            ->middleware('scope:assignments:read', 'throttle:api')
            ->name('assignments.show');
        Route::get('assignments/simulations/{simulation}/students/{student}', [AssignmentApiController::class, 'student'])
            ->whereNumber(['simulation', 'student'])
            ->middleware('scope:assignments:read', 'throttle:api')
            ->name('assignments.student');
        Route::post('assignments/simulations/{simulation}/groups/{groupe}/confirm', [AssignmentApiController::class, 'confirmer'])
            ->whereNumber(['simulation', 'groupe'])
            ->middleware('scope:assignments:write', 'throttle:api-imports')
            ->name('assignments.confirm');

        // ── Exports (profils restreints, filtres en liste blanche) ──

        Route::post('exports', [ExportApiController::class, 'store'])
            ->middleware('scope:exports:create', 'throttle:api-exports', 'idempotency')
            ->name('exports.store');
        Route::get('exports/{report}', [ExportApiController::class, 'show'])
            ->whereNumber('report')
            ->middleware('scope:exports:create', 'throttle:api')
            ->name('exports.show');
        Route::get('exports/{report}/download', [ExportApiController::class, 'download'])
            ->whereNumber('report')
            ->middleware('scope:exports:create', 'throttle:api-exports')
            ->name('exports.download');

        // ── Versioning interne (§49) ──
        Route::get('version/status', [VersioningApiController::class, 'status'])
            ->middleware('scope:versioning:read', 'throttle:api')
            ->name('version.status');
        Route::get('version/manifest', [VersioningApiController::class, 'manifest'])
            ->middleware('scope:versioning:read', 'throttle:api')
            ->name('version.manifest');
        Route::get('version/compatibility', [VersioningApiController::class, 'compatibility'])
            ->middleware('scope:versioning:read', 'throttle:api')
            ->name('version.compatibility');
        Route::get('version/drift', [VersioningApiController::class, 'drift'])
            ->middleware('scope:versioning:read', 'throttle:api')
            ->name('version.drift');
    });
});
