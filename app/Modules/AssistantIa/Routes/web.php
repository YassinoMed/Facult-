<?php

use App\Modules\AssistantIa\Controllers\AssistantIaController;
use App\Modules\AssistantIa\Controllers\AssistantIaPopupController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'module:assistant-ia', 'can:assistant_ia.view'])
    ->prefix('admin/assistant-ia')
    ->name('admin.assistant-ia.')
    ->group(function () {
        Route::get('/', [AssistantIaController::class, 'index'])->name('index');
        Route::get('/conversations', [AssistantIaController::class, 'index'])->name('conversations.index');
        Route::post('/conversations', [AssistantIaController::class, 'creerConversation'])->name('conversations.creer');
        Route::get('/conversations/{uuid}', [AssistantIaController::class, 'afficherConversation'])->name('conversations.show');
        Route::post('/conversations/{uuid}/messages', [AssistantIaController::class, 'envoyerMessage'])->middleware('throttle:30,1')->name('messages.envoyer');
        Route::post('/conversations/{uuid}/changer-fournisseur', [AssistantIaController::class, 'changerFournisseur'])->name('conversations.changer_fournisseur');
        Route::delete('/conversations/{uuid}', [AssistantIaController::class, 'supprimerConversation'])->name('conversations.supprimer');
        Route::post('/actions/{id}/approuver', [AssistantIaController::class, 'approuverAction'])->name('actions.approuver')->middleware('can:assistant_ia.action_approuver');
        Route::post('/actions/{id}/rejeter', [AssistantIaController::class, 'rejeterAction'])->name('actions.rejeter')->middleware('can:assistant_ia.action_approuver');
        Route::get('/sources', [AssistantIaController::class, 'sources'])->name('sources.index')->middleware('can:assistant_ia.admin');
        Route::post('/sources/initialiser', [AssistantIaController::class, 'initialiserSources'])->name('sources.initialiser')->middleware('can:assistant_ia.admin');
        Route::post('/sources/synchroniser-tout', [AssistantIaController::class, 'synchroniserToutesSources'])->name('sources.synchroniser_tout')->middleware('can:assistant_ia.admin');
        Route::post('/sources/{id}/synchroniser', [AssistantIaController::class, 'synchroniserSource'])->name('sources.synchroniser')->middleware('can:assistant_ia.admin');
        Route::post('/sources/{id}/basculer', [AssistantIaController::class, 'basculerStatutSource'])->name('sources.basculer')->middleware('can:assistant_ia.admin');
        Route::post('/consentement', [AssistantIaController::class, 'genererConsentement'])->name('consentement.generer');
        Route::get('/statut-fournisseurs', [AssistantIaController::class, 'statutFournisseurs'])->name('fournisseurs.statut');
    });

/*
|--------------------------------------------------------------------------
| Routes du Popup UniRegistre AI (Multiportail : Admin, Enseignant, Étudiant)
|--------------------------------------------------------------------------
| Protégées par la session web, la vérification du module actif et la
| résolution sécurisée de l'acteur (anti-IDOR, isolation stricte).
*/
Route::middleware(['web', 'module:assistant-ia'])
    ->prefix('assistant-ia/popup')
    ->name('assistant-ia.popup.')
    ->group(function () {
        Route::get('/initialiser', [AssistantIaPopupController::class, 'initialiser'])->name('initialiser');
        Route::post('/contexte', [AssistantIaPopupController::class, 'initialiser'])->name('contexte');
        Route::post('/conversations', [AssistantIaPopupController::class, 'creerConversation'])->name('conversations.creer');
        Route::get('/conversations/{uuid}', [AssistantIaPopupController::class, 'afficherConversation'])->name('conversations.show');
        Route::post('/conversations/{uuid}/messages', [AssistantIaPopupController::class, 'envoyerMessage'])->middleware('throttle:30,1')->name('messages.envoyer');
        Route::delete('/conversations/{uuid}', [AssistantIaPopupController::class, 'supprimerConversation'])->name('conversations.supprimer');
        Route::post('/actions/preparer', [AssistantIaPopupController::class, 'preparerAction'])->name('actions.preparer');
        Route::post('/actions/{uuid}/confirmer', [AssistantIaPopupController::class, 'confirmerAction'])->name('actions.confirmer');
    });
