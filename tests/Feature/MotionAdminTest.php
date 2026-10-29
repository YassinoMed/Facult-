<?php

namespace Tests\Feature;

use App\Models\Faculte;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Portée de mouvement du back-office.
 *
 * Toutes les pages `/admin/*` authentifiées exposent `data-motion="admin"`
 * pour charger le module GSAP borné `resources/js/admin.js` (sans Lenis).
 * Les écrans d'authentification (connexion, mot de passe) restent en `app` :
 * aucune animation ne doit s'interposer dans un parcours de sécurité.
 */
class MotionAdminTest extends TestCase
{
    use RefreshDatabase;

    private function creerAdmin(): User
    {
        Faculte::create([
            'nom' => 'Faculté des Sciences',
            'code' => 'FST',
            'description' => 'Sciences',
            'active' => true,
        ]);

        return User::creerCompteBackOffice([
            'name' => 'Scolarité Motion',
            'email' => 'motion.admin@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    public function test_le_back_office_charge_la_portee_admin(): void
    {
        $this->actingAs($this->creerAdmin())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('data-motion="admin"', false);
    }

    public function test_les_pages_admin_imbriquees_chargent_la_portee_admin(): void
    {
        $this->actingAs($this->creerAdmin())
            ->get(route('admin.etudiants.index'))
            ->assertOk()
            ->assertSee('data-motion="admin"', false);
    }

    public function test_les_ecrans_d_authentification_admin_restent_sobres(): void
    {
        $this->get(route('admin.connexion.show'))
            ->assertOk()
            ->assertSee('data-motion="app"', false);

        $this->get(route('admin.mot-de-passe.oublie'))
            ->assertOk()
            ->assertSee('data-motion="app"', false);
    }
}
