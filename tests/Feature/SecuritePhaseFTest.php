<?php

namespace Tests\Feature;

use App\Modules\Sentinel\Services\Checks\FileAttenteCheck;
use App\Services\MultiFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

/**
 * Phase F — Sécurité/notifications/intégrations : verrouillage des
 * garanties existantes (MFA absente = décision reportée, non implémentée).
 */
class SecuritePhaseFTest extends TestCase
{
    use RefreshDatabase;

    public function test_garanties_authentification(): void
    {
        // Throttles connexion 5/min sur les 3 espaces.
        foreach (['admin.connexion.login', 'etudiant.connexion.login', 'enseignant.connexion.login'] as $route) {
            $middlewares = collect(Route::getRoutes())
                ->firstWhere('action.as', $route)?->gatherMiddleware() ?? [];
            $this->assertContains('throttle:5,1', $middlewares, $route);
        }

        // Politique mot de passe : 9 caractères rejetés, 10+ lettres+chiffres acceptés.
        $faible = Validator::make(
            ['password' => 'Abcde1234'], ['password' => Password::defaults()]
        );
        $this->assertTrue($faible->fails());
        $fort = Validator::make(
            ['password' => 'Abcdef1234'], ['password' => Password::defaults()]
        );
        $this->assertFalse($fort->fails());
    }

    public function test_sessions_et_files_observables(): void
    {
        $this->assertTrue(Route::has('admin.sessions.index'));
        $this->assertTrue(Route::has('admin.sessions.destroy'));
        $this->assertTrue(Route::has('admin.maintenance.index'));
        $this->assertTrue(class_exists(FileAttenteCheck::class));
    }

    public function test_mfa_absente_documentee(): void
    {
        // Aucun mécanisme TOTP/WebAuthn : décision de report (mot de passe
        // fort + throttling + sessions + reset admin jugés suffisants).
        $this->assertFalse(class_exists(MultiFactorService::class));
    }
}
