<?php

use App\Modules\AiAssistant\Controllers\AdminAiAssistantController;
use Illuminate\Support\Facades\Route;

/*
| Routes du module AI-ASSISTANT (chargées avec ['web', 'module:ai-assistant']).
| 404 immédiat tant que le module est désactivé (convention §11).
| Chaque action ajoute auth + permission module-locale ('can:ai.*').
*/

Route::name('admin.ai.')->prefix('admin/ai')->group(function () {
    Route::get('/', [AdminAiAssistantController::class, 'index'])
        ->middleware(['auth', 'can:ai.acceder'])
        ->name('index');

    Route::get('conversations', [AdminAiAssistantController::class, 'conversations'])
        ->middleware(['auth', 'can:ai.acceder'])
        ->name('conversations.index');

    Route::post('conversations', [AdminAiAssistantController::class, 'creerConversation'])
        ->middleware(['auth', 'can:ai.acceder'])
        ->name('conversations.store');

    Route::get('conversations/{conversation}', [AdminAiAssistantController::class, 'messages'])
        ->middleware(['auth', 'can:ai.acceder'])
        ->name('conversations.messages');

    Route::post('conversations/{conversation}/messages', [AdminAiAssistantController::class, 'envoyerMessage'])
        ->middleware(['auth', 'can:ai.acceder', 'throttle:30,1'])
        ->name('conversations.messages.store');

    Route::delete('conversations/{conversation}', [AdminAiAssistantController::class, 'detruire'])
        ->middleware(['auth', 'can:ai.acceder'])
        ->name('conversations.destroy');
});
