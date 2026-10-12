<?php

use App\Modules\StudentDossiers\Controllers\MonDossierController;
use App\Modules\StudentDossiers\Controllers\OfficialDocumentController;
use App\Modules\StudentDossiers\Controllers\StudentDocumentController;
use App\Modules\StudentDossiers\Controllers\StudentDossierController;
use App\Modules\StudentDossiers\Controllers\StudentDossierPdfController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module StudentDossiers — routes (§7)
|--------------------------------------------------------------------------
| Toujours enregistrées, verrouillées par `module:student-dossiers` :
| module désactivé = 404, même en appel direct (serveur uniquement).
*/
Route::middleware(['auth', 'admin', 'module:student-dossiers'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('student-dossiers', [StudentDossierController::class, 'index'])
        ->middleware('can:student_dossiers.view')
        ->name('student-dossiers.index');
    Route::post('student-dossiers', [StudentDossierController::class, 'store'])
        ->middleware(['can:student_dossiers.create', 'throttle:20,1'])
        ->name('student-dossiers.store');
    Route::get('student-dossiers/{dossier}', [StudentDossierController::class, 'show'])
        ->middleware('can:student_dossiers.view')
        ->name('student-dossiers.show');
    Route::delete('student-dossiers/{dossier}', [StudentDossierController::class, 'destroy'])
        ->middleware(['can:student_dossiers.delete', 'throttle:10,1'])
        ->name('student-dossiers.destroy');

    Route::post('student-dossiers/{dossier}/documents', [StudentDocumentController::class, 'store'])
        ->middleware(['can:student_documents.upload', 'throttle:10,1'])
        ->name('student-dossiers.documents.store');
    Route::post('student-dossiers/documents/{document}/replace', [StudentDocumentController::class, 'replace'])
        ->middleware(['can:student_documents.replace', 'throttle:10,1'])
        ->name('student-dossiers.documents.replace');
    Route::patch('student-dossiers/documents/{document}/validate', [StudentDocumentController::class, 'validate'])
        ->middleware(['can:student_documents.validate', 'throttle:20,1'])
        ->name('student-dossiers.documents.validate');
    Route::patch('student-dossiers/documents/{document}/reject', [StudentDocumentController::class, 'reject'])
        ->middleware(['can:student_documents.reject', 'throttle:20,1'])
        ->name('student-dossiers.documents.reject');
    Route::delete('student-dossiers/documents/{document}', [StudentDocumentController::class, 'destroy'])
        ->middleware(['can:student_documents.delete', 'throttle:10,1'])
        ->name('student-dossiers.documents.destroy');
    Route::get('student-dossiers/{dossier}/documents/{document}/preview', [StudentDocumentController::class, 'preview'])
        ->middleware(['can:student_documents.download', 'throttle:20,1'])
        ->name('student-dossiers.documents.preview');
    Route::get('student-dossiers/{dossier}/documents/{document}/download', [StudentDocumentController::class, 'download'])
        ->middleware(['can:student_documents.download', 'throttle:20,1'])
        ->name('student-dossiers.documents.download');
    Route::get('student-dossiers/{dossier}/documents/{document}/versions/{version}/download', [StudentDocumentController::class, 'downloadVersion'])
        ->middleware(['can:student_documents.download', 'throttle:20,1'])
        ->name('student-dossiers.documents.versions.download');

    // PDF du dossier (§10-12) : génération, prévisualisation, flux, téléchargement, impression.
    Route::post('student-dossiers/{dossier}/pdf/generer', [StudentDossierPdfController::class, 'generer'])
        ->middleware('throttle:10,1')
        ->name('student-dossiers.pdf.generer');
    Route::get('student-dossiers/{dossier}/pdf/preview', [StudentDossierPdfController::class, 'preview'])
        ->middleware('throttle:20,1')
        ->name('student-dossiers.pdf.preview');
    Route::get('student-dossiers/{dossier}/pdf/fichier', [StudentDossierPdfController::class, 'fichier'])
        ->middleware('throttle:20,1')
        ->name('student-dossiers.pdf.fichier');
    Route::get('student-dossiers/{dossier}/pdf/download', [StudentDossierPdfController::class, 'download'])
        ->middleware('throttle:20,1')
        ->name('student-dossiers.pdf.download');
    Route::get('student-dossiers/{dossier}/pdf/print', [StudentDossierPdfController::class, 'print'])
        ->middleware('throttle:20,1')
        ->name('student-dossiers.pdf.print');

    // Documents officiels (Phase 7) : génération, téléchargement,
    // révocation — policy student_dossiers.pdf, zéro permission créée.
    Route::post('etudiants/{etudiant}/documents-officiels', [OfficialDocumentController::class, 'generer'])
        ->middleware('throttle:10,1')
        ->name('documents-officiels.generer');
    Route::get('documents-officiels/{document}/download', [OfficialDocumentController::class, 'download'])
        ->middleware('throttle:20,1')
        ->name('documents-officiels.download');
    Route::post('documents-officiels/{document}/revoquer', [OfficialDocumentController::class, 'revoquer'])
        ->middleware('throttle:10,1')
        ->name('documents-officiels.revoquer');
});

Route::middleware(['etudiant.auth', 'module:student-dossiers'])->prefix('etudiant')->name('etudiant.')->group(function () {
    Route::get('dossier', [MonDossierController::class, 'show'])->name('dossier.show');
    Route::post('dossier/documents', [MonDossierController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('dossier.documents.store');
    Route::post('dossier/documents/{document}/replace', [MonDossierController::class, 'replace'])
        ->middleware('throttle:10,1')
        ->name('dossier.documents.replace');
    Route::get('dossier/documents/{document}/preview', [MonDossierController::class, 'preview'])
        ->middleware('throttle:20,1')
        ->name('dossier.documents.preview');
    Route::get('dossier/documents/{document}/download', [MonDossierController::class, 'download'])
        ->middleware('throttle:20,1')
        ->name('dossier.documents.download');
    Route::get('dossier/documents/{document}/versions/{version}/download', [MonDossierController::class, 'downloadVersion'])
        ->middleware('throttle:20,1')
        ->name('dossier.documents.versions.download');

    // Relevés et attestations officiels (LOOP 4 §38) : liste + téléchargement,
    // strictement limités au dossier connecté (anti-IDOR par session).
    Route::get('dossier/officiels', [MonDossierController::class, 'officiels'])
        ->middleware('throttle:20,1')
        ->name('dossier.officiels.index');
    Route::get('dossier/officiels/{document}/download', [MonDossierController::class, 'telechargerOfficiel'])
        ->middleware('throttle:20,1')
        ->name('dossier.officiels.download');

    Route::get('dossier/pdf/preview', [StudentDossierPdfController::class, 'apercu'])
        ->middleware('throttle:20,1')
        ->name('dossier.pdf.preview');
    Route::get('dossier/pdf/fichier', [StudentDossierPdfController::class, 'fichierEtu'])
        ->middleware('throttle:20,1')
        ->name('dossier.pdf.fichier');
    Route::get('dossier/pdf/download', [StudentDossierPdfController::class, 'telecharger'])
        ->middleware('throttle:20,1')
        ->name('dossier.pdf.download');
    Route::get('dossier/pdf/print', [StudentDossierPdfController::class, 'imprimer'])
        ->middleware('throttle:20,1')
        ->name('dossier.pdf.print');
    Route::post('dossier/pdf/generer', [StudentDossierPdfController::class, 'genererEtu'])
        ->middleware('throttle:10,1')
        ->name('dossier.pdf.generer');
});
