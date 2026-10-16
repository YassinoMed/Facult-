<?php

use App\Http\Controllers\Admin\AppelsController;
use App\Http\Controllers\Admin\RegularisationPresenceController;
use App\Modules\Attendance\Controllers\AppelController;
use App\Modules\Attendance\Controllers\AttendanceTemplateController;
use App\Modules\Attendance\Controllers\JustificationController;
use App\Modules\Attendance\Controllers\MesPresencesController;
use App\Modules\Attendance\Controllers\PointageQrController;
use App\Modules\Attendance\Controllers\PresenceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module Attendance — routes (§11/§31)
|--------------------------------------------------------------------------
| Toujours enregistrées, mais verrouillées par `module:attendance` :
| module désactivé = 404, même en appel direct. L'activation est
| serveur uniquement (config/modules.php ← env).
*/
Route::middleware(['auth', 'admin', 'secondaire.verrou', 'module:attendance'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('presences', [PresenceController::class, 'index'])
        ->middleware('can:attendance.view')
        ->name('presences.index');

    // Feuilles de présence multi-modèles & assistant
    Route::get('presences/feuille/nouveau', [PresenceController::class, 'nouveauFeuille'])
        ->middleware('can:attendance.export')
        ->name('presences.feuille.nouveau');
    Route::get('presences/feuille/apercu', [PresenceController::class, 'apercuFeuille'])
        ->middleware('can:attendance.export')
        ->name('presences.feuille.apercu');
    Route::get('presences/feuille', [PresenceController::class, 'feuille'])
        ->middleware(['can:attendance.export', 'throttle:10,1'])
        ->name('presences.feuille');

    // Scan pointage QR Code sécurisé
    Route::post('presences/qr/scanner', [PresenceController::class, 'scannerQr'])
        ->middleware(['can:attendance.create', 'throttle:60,1'])
        ->name('presences.qr.scanner');

    // Administration des modèles de fiches
    Route::prefix('presences/modeles')->name('presences.modeles.')->group(function () {
        Route::get('/', [AttendanceTemplateController::class, 'index'])
            ->middleware('can:attendance.templates.manage')
            ->name('index');
        Route::get('/creer', [AttendanceTemplateController::class, 'create'])
            ->middleware('can:attendance.templates.manage')
            ->name('create');
        Route::post('/', [AttendanceTemplateController::class, 'store'])
            ->middleware('can:attendance.templates.manage')
            ->name('store');
        Route::get('/{template}/editer', [AttendanceTemplateController::class, 'edit'])
            ->middleware('can:attendance.templates.manage')
            ->name('edit');
        Route::put('/{template}', [AttendanceTemplateController::class, 'update'])
            ->middleware('can:attendance.templates.manage')
            ->name('update');
        Route::post('/{template}/dupliquer', [AttendanceTemplateController::class, 'duplicate'])
            ->middleware('can:attendance.templates.manage')
            ->name('duplicate');
        Route::patch('/{template}/toggle', [AttendanceTemplateController::class, 'toggle'])
            ->middleware('can:attendance.templates.manage')
            ->name('toggle');
        Route::delete('/{template}', [AttendanceTemplateController::class, 'destroy'])
            ->middleware('can:attendance.templates.manage')
            ->name('destroy');
        Route::get('/{template}/apercu', [AttendanceTemplateController::class, 'apercu'])
            ->middleware('can:attendance.templates.use')
            ->name('apercu');
        Route::get('/{template}/apercu-pdf', [AttendanceTemplateController::class, 'apercuPdf'])
            ->middleware('can:attendance.templates.use')
            ->name('apercu-pdf');
    });

    Route::post('presences', [PresenceController::class, 'store'])
        ->middleware(['can:attendance.create', 'throttle:20,1'])
        ->name('presences.store');
    Route::delete('presences/{presence}', [PresenceController::class, 'destroy'])
        ->middleware(['can:attendance.delete', 'throttle:10,1'])
        ->name('presences.destroy');

    // Présences intelligentes des enseignants liées à l'emploi du temps (§1-§8)
    Route::prefix('presences/enseignants')->name('presences.enseignants.')->group(function () {
        Route::post('reconcilier', [PresenceController::class, 'reconcilierEnseignants'])
            ->middleware(['can:attendance.create', 'throttle:30,1'])
            ->name('reconcilier');
        Route::post('pointage', [PresenceController::class, 'enregistrerPointageEnseignant'])
            ->middleware(['can:attendance.create', 'throttle:60,1'])
            ->name('pointage');
        Route::post('{presence}/valider', [PresenceController::class, 'validerPresenceEnseignant'])
            ->middleware(['can:attendance.create', 'throttle:30,1'])
            ->name('valider');
        Route::post('{presence}/confirmer', [PresenceController::class, 'confirmerPresenceEnseignant'])
            ->middleware(['can:attendance.create', 'throttle:30,1'])
            ->name('confirmer');
        Route::post('{presence}/corriger', [PresenceController::class, 'corrigerPresenceEnseignant'])
            ->middleware(['can:attendance.create', 'throttle:30,1'])
            ->name('corriger');
        Route::post('{presence}/dispenser', [PresenceController::class, 'dispenserEnseignant'])
            ->middleware(['can:attendance.create', 'throttle:30,1'])
            ->name('dispenser');
        Route::get('{presence}/historique', [PresenceController::class, 'historiqueEnseignant'])
            ->middleware('can:attendance.view')
            ->name('historique');
    });

    // Appels de présence (enseignants) : sélection, détail, incomplets.
    Route::get('appels', [AppelsController::class, 'index'])
        ->middleware('can:attendance.view')
        ->name('appels.index');
    Route::get('appels/{appel}', [AppelsController::class, 'show'])
        ->middleware('can:attendance.view')
        ->name('appels.show');

    // Justifications : file scolarité + validation (workflow serveur).
    Route::get('justifications', [JustificationController::class, 'index'])
        ->middleware('can:attendance.view')
        ->name('justifications.index');
    Route::post('justifications/{justification}/valider', [JustificationController::class, 'valider'])
        ->middleware(['can:attendance.justify', 'throttle:20,1'])
        ->name('justifications.valider');

    // Régularisations enseignants : file des demandes de régularisation
    // (catégorie `presence`) + traitement tracé (résoudre/rejeter).
    Route::prefix('presences/regularisations')->name('presences.regularisations.')->group(function () {
        Route::get('/', [RegularisationPresenceController::class, 'index'])
            ->middleware('can:attendance.view')
            ->name('index');
        Route::get('/{reclamation}', [RegularisationPresenceController::class, 'show'])
            ->middleware('can:attendance.view')
            ->name('show');
        Route::get('/{reclamation}/piece-jointe', [RegularisationPresenceController::class, 'pieceJointe'])
            ->middleware(['can:attendance.view', 'throttle:20,1'])
            ->name('piece-jointe');
        Route::post('/{reclamation}/traiter', [RegularisationPresenceController::class, 'traiter'])
            ->middleware(['can:attendance.create', 'throttle:10,1'])
            ->name('traiter');
    });
});

Route::middleware(['etudiant.auth', 'module:attendance'])->prefix('etudiant')->name('etudiant.')->group(function () {
    Route::get('absences', [PresenceController::class, 'mesAbsences'])->name('absences.index');
    Route::get('absences/export', [PresenceController::class, 'exporterAbsences'])
        ->middleware('throttle:10,1')
        ->name('absences.exporter');
    Route::post('absences/{presence}/justifier', [JustificationController::class, 'deposer'])
        ->middleware('throttle:10,1')
        ->name('absences.justifier');

    // Pointage via QR temporaire d'appel (LOOP 21) : jeton nominatif,
    // appel en cours du groupe, idempotent.
    Route::get('appel/qr', [PointageQrController::class, 'formulaire'])->name('appel.qr');
    Route::post('appel/qr', [PointageQrController::class, 'pointer'])
        ->middleware('throttle:10,1')
        ->name('appel.pointer');
});

Route::middleware(['enseignant.auth', 'module:attendance'])->prefix('enseignant')->name('enseignant.')->group(function () {
    Route::get('presences', [MesPresencesController::class, 'index'])->name('presences.index');
    Route::get('presences/export-csv', [MesPresencesController::class, 'exporter'])
        ->middleware('throttle:10,1')
        ->name('presences.export');
    Route::get('presences/{presence}', [MesPresencesController::class, 'show'])->name('presences.show');

    // Appel intelligent : sélection, feuille, enregistrement, clôture, correction.
    Route::get('appel', [AppelController::class, 'selection'])->name('appel.selection');
    Route::match(['get', 'post'], 'appel/ouvrir/{slot}', [AppelController::class, 'ouvrir'])
        ->middleware('throttle:30,1')
        ->name('appel.ouvrir');
    Route::get('appel/{appel}', [AppelController::class, 'feuille'])->name('appel.feuille');
    Route::post('appel/{appel}/enregistrer', [AppelController::class, 'enregistrer'])
        ->middleware('throttle:20,1')
        ->name('appel.enregistrer');
    Route::post('appel/{appel}/cloturer', [AppelController::class, 'cloturer'])
        ->middleware('throttle:10,1')
        ->name('appel.cloturer');
    Route::post('appel/{appel}/corriger', [AppelController::class, 'corriger'])
        ->middleware('throttle:20,1')
        ->name('appel.corriger');
    Route::get('appel/{appel}/export', [AppelController::class, 'exporter'])
        ->middleware('throttle:10,1')
        ->name('appel.exporter');
    Route::get('appel/{appel}/qr/{etudiant}', [AppelController::class, 'qr'])
        ->middleware('throttle:30,1')
        ->name('appel.qr');
});
