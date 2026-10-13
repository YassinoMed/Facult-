<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase D — Comptes : recherche et filtres du registre, sans fuite.
 */
class ComptesPhaseDTest extends TestCase
{
    use RefreshDatabase;

    private string $emailSuperAdmin = '';

    private function superAdmin(): void
    {
        $this->emailSuperAdmin = uniqid('zzz.', true).'@scolarite.test';
        $user = User::creerCompteBackOffice([
            'name' => 'Zzz Top', 'email' => $this->emailSuperAdmin,
            'password' => 'Motdepasse@123',
        ], 'super_admin');
        $this->actingAs($user);
    }

    public function test_recherche_et_filtres(): void
    {
        $this->superAdmin();
        User::creerCompteBackOffice([
            'name' => 'Cherche Cible', 'email' => 'cible.admin@scolarite.test',
            'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        $this->get(route('admin.administrateurs.index', ['search' => 'Cible']))
            ->assertOk()->assertSee('Cherche Cible')->assertDontSee($this->emailSuperAdmin);
        $this->get(route('admin.administrateurs.index', ['role' => 'agent_scolarite']))
            ->assertOk()->assertSee('cible.admin@scolarite.test');
        $this->get(route('admin.administrateurs.index', ['role' => 'inconnu']))
            ->assertOk()->assertSee('Aucun');
    }

    public function test_sans_mot_de_passe_expose(): void
    {
        $this->superAdmin();

        $contenu = $this->get(route('admin.administrateurs.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Motdepasse@123', $contenu);
        $this->assertStringNotContainsString('$2y$', $contenu);
    }
}
