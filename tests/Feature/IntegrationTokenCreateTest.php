<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit §P2-14 : la commande `integration:token-create` valide le rôle
 * contre la matrice RBAC, interdit `super_admin` aux comptes techniques et
 * refuse de « recycler » silencieusement un compte au rôle différent.
 */
class IntegrationTokenCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_inconnu_refuse(): void
    {
        $this->artisan('integration:token-create', [
            '--name' => 'refuse',
            '--role' => 'president',
            '--abilities' => ['students:read'],
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'refuse@integration.systeme']);
    }

    public function test_role_super_admin_interdit_aux_comptes_techniques(): void
    {
        $this->artisan('integration:token-create', [
            '--name' => 'trop-puissant',
            '--role' => 'super_admin',
            '--abilities' => ['students:read'],
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'trop-puissant@integration.systeme']);
    }

    public function test_role_valide_cree_le_compte_et_le_token(): void
    {
        $this->artisan('integration:token-create', [
            '--name' => 'scolarite-apipro',
            '--role' => 'admin_scolarite',
            '--abilities' => ['students:read'],
        ])->assertSuccessful();

        $compte = User::where('email', 'scolarite-apipro@integration.systeme')->firstOrFail();
        $this->assertSame('admin_scolarite', $compte->role);
        $this->assertSame(1, $compte->tokens()->count());
    }

    public function test_compte_existant_avec_un_autre_role_refuse(): void
    {
        User::creerCompteBackOffice([
            'name' => 'Existant',
            'email' => 'doublon@integration.systeme',
            'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        $this->artisan('integration:token-create', [
            '--name' => 'doublon',
            '--role' => 'admin_scolarite',
            '--abilities' => ['students:read'],
        ])->assertFailed();

        // Rôle d'origine inchangé, aucun token créé.
        $this->assertSame('agent_scolarite', User::where('email', 'doublon@integration.systeme')->firstOrFail()->role);
        $this->assertSame(0, User::where('email', 'doublon@integration.systeme')->firstOrFail()->tokens()->count());
    }
}
