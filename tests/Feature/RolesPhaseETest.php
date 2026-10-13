<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase E — Rôles : verrou anti-escalade et gel des permissions candidates.
 */
class RolesPhaseETest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $user = User::creerCompteBackOffice([
            'name' => 'Super', 'email' => uniqid('re.', true).'@scolarite.test',
            'password' => 'Motdepasse@123',
        ], 'super_admin');
        $this->actingAs($user);

        return $user;
    }

    public function test_propre_role_inchangeable_et_dernier_super_admin_protege(): void
    {
        $superAdmin = $this->superAdmin();

        $this->patch(route('admin.administrateurs.update', $superAdmin), [
            'name' => 'Super', 'role' => 'consultation',
        ])->assertForbidden();

        $this->patch(route('admin.administrateurs.update', $superAdmin), [
            'name' => 'Super', 'role' => 'super_admin',
        ])->assertRedirect();
        $this->assertSame('super_admin', $superAdmin->fresh()->role);
    }

    public function test_permissions_candidates_creees_sauf_doublon(): void
    {
        // LOOP J : 16 permissions créées (super_admin seul, matrice
        // inchangée pour les autres rôles). Seule `audit.view` reste
        // proposition : doublon de `history.view` existante (anti-doublon).
        foreach (['settings.history.view', 'settings.restore', 'settings.diagnostics.view', 'users.view', 'users.create', 'users.update', 'users.suspend', 'users.sessions.revoke', 'roles.view', 'roles.manage', 'permissions.view', 'permissions.assign', 'mail.settings.manage', 'mail.test.send', 'mail.logs.view'] as $creee) {
            $this->assertContains($creee, Permissions::TOUTES, $creee);
        }
        $this->assertNotContains('audit.view', Permissions::TOUTES);

        // Zéro élargissement : les autres rôles ne reçoivent rien.
        foreach (['admin_scolarite', 'admin_faculte', 'agent_scolarite', 'consultation'] as $role) {
            $this->assertNotContains('roles.view', Permissions::pourRole($role), $role);
            $this->assertNotContains('settings.restore', Permissions::pourRole($role), $role);
            $this->assertNotContains('mail.settings.manage', Permissions::pourRole($role), $role);
        }
    }

    public function test_matrice_roles_lecture_super_admin_seul(): void
    {
        $this->superAdmin();
        $this->get(route('admin.roles.index'))->assertOk()
            ->assertSee('super_admin')
            ->assertSee('archives.delete');

        $agent = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => uniqid('ag.', true).'@scolarite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->actingAs($agent);
        $this->get(route('admin.roles.index'))->assertForbidden();
    }

    public function test_agent_sans_acces_comptes(): void
    {
        $this->superAdmin();
        $agent = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => uniqid('ag.', true).'@scolarite.test',
            'password' => 'Motdepasse@123',
        ], 'agent_scolarite');
        $this->actingAs($agent);

        $this->get(route('admin.administrateurs.index'))->assertForbidden();
    }
}
