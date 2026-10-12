<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Navigation globale (§7-8/§17/§31) : sidebar par rôle, dashboards
 * vivants, isolation des menus.
 */
class NavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function creerFaculte(): Faculte
    {
        return Faculte::create([
            'nom' => 'Faculté des Sciences', 'code' => 'FST',
            'description' => 'Sciences', 'active' => true,
        ]);
    }

    protected function creerCompte(string $email, string $role): User
    {
        return User::creerCompteBackOffice([
            'name' => $role, 'email' => $email, 'password' => 'Motdepasse@123',
        ], $role);
    }

    public function test_invite_sidebar_portail(): void
    {
        $this->get(route('inscription.create'))
            ->assertOk()
            ->assertSee('Portail')
            ->assertSee('Inscription')
            ->assertSee('Espace scolarité')
            ->assertSee('aria-label="Navigation principale"', false)
            ->assertSee('sidebar-collapsed', false)
            ->assertSee('aria-expanded', false)
            ->assertSee('localStorage.getItem', false)
            ->assertSee('Choisir le thème', false)
            ->assertDontSee('Registre des étudiants');
    }

    public function test_etudiant_sidebar_espace_et_dashboard_vivant(): void
    {
        $faculte = $this->creerFaculte();
        $etudiant = Etudiant::create([
            'cin' => 'BK960001', 'nom' => 'Test', 'prenom' => 'Nadia',
            'email' => 'bk960001@example.com', 'sexe' => 'F',
            'faculte_id' => $faculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'Rue',
            'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'motdepasse1',
        ]);
        $etudiant->forceFill(['num_inscription' => 'INS-2026-BK960001'])->save();
        $etudiant->definirStatut('valide');

        session(['etudiant_auth_id' => $etudiant->fresh()->id]);

        $this->get(route('etudiant.dashboard'))
            ->assertOk()
            ->assertSee('Bonjour Nadia')
            ->assertSee("Aujourd'hui", false)
            ->assertSee('Dernier résultat')
            ->assertSee('Documents')
            ->assertSee('Mon espace')
            ->assertSee('Mes notes')
            ->assertDontSee('Registre des étudiants')
            ->assertDontSee('Espace scolarité');
    }

    public function test_admin_sidebar_pilotage_et_actions_rapides(): void
    {
        $this->creerFaculte();
        $admin = $this->creerCompte('sco@universite.test', 'admin_scolarite');

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Pilotage')
            ->assertSee('Scolarité')
            ->assertSee('Registre des étudiants')
            ->assertSee('Actions rapides')
            ->assertSee('Activité récente')
            ->assertDontSee('Mon espace');
    }

    public function test_admin_faculte_sans_section_super_admin(): void
    {
        $faculte = $this->creerFaculte();
        $admin = User::creerCompteBackOffice([
            'name' => 'Admin F', 'email' => 'admin.f@universite.test',
            'password' => 'Motdepasse@123', 'faculte_id' => $faculte->id,
        ], 'admin_faculte');

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Ma Faculté')
            ->assertDontSee('Administrateurs');
    }
}
