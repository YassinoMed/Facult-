<?php

namespace App\Modules\AiAssistant\Providers;

use App\Modules\AiAssistant\Console\ReindexerDocumentaire;
use App\Modules\AiAssistant\Contracts\AiFournisseur;
use App\Modules\AiAssistant\Contracts\RechercheDocumentaire;
use App\Modules\AiAssistant\Models\AiConversation;
use App\Modules\AiAssistant\Policies\ConversationPolicy;
use App\Modules\AiAssistant\Services\AiChatService;
use App\Modules\AiAssistant\Services\RechercheIndexPartage;
use App\Modules\AiAssistant\Support\EnregistreurOutils;
use App\Modules\AiAssistant\Support\GestionnaireFournisseur;
use App\Modules\AiAssistant\Support\PermissionsAi;
use App\Modules\AiAssistant\Support\RegistreOutils;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Fournisseur du module AI-ASSISTANT (Lot 1) — AUTOREGISTRÉ dans
 * bootstrap/providers.php. Cette autoregistration évite d'éditer
 * ModuleServiceProvider.php (modifié par un lot concurrent).
 *
 * Charges de boot :
 * - les Gates IA (module-local, matrice PermissionsAi) ;
 * - les routes du module, derrière le middleware `module:ai-assistant`
 *   (404 immédiat tant que le module est désactivé — convention §11).
 */
final class AiAssistantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RegistreOutils::class);
        $this->app->singleton(GestionnaireFournisseur::class);
        $this->app->singleton(AiChatService::class);

        // Lot 2 : le contrat AiFournisseur se résout par configuration.
        $this->app->bind(AiFournisseur::class, fn ($app) => $app->make(GestionnaireFournisseur::class)->actif());

        // Lot 6 : base documentaire réelle — table partagée
        // `ai_documents_index` (consommée) + registre local par fichier ;
        // aperçu : recherche filtrée AVANT restitution, réindexage
        // explicite, anti-doublon sha256, statut retire réversible.
        $this->app->bind(RechercheDocumentaire::class, RechercheIndexPartage::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ReindexerDocumentaire::class]);
        }

        EnregistreurOutils::enregistrer($this->app->make(RegistreOutils::class));

        foreach (PermissionsAi::TOUTES as $permission) {
            Gate::define($permission, fn ($utilisateur) => $utilisateur !== null
                && PermissionsAi::permise($permission, (string) ($utilisateur->role ?? '')));
        }

        Gate::policy(AiConversation::class, ConversationPolicy::class);

        Route::middleware(['web', 'module:ai-assistant'])->group(
            base_path('app/Modules/AiAssistant/Routes/web.php')
        );
    }
}
