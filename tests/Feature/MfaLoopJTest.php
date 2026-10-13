<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MfaTotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP J — MFA TOTP : défi au login, activation en deux temps,
 * désactivation, porte de secours par réinitialisation.
 */
class MfaLoopJTest extends TestCase
{
    use RefreshDatabase;

    private function compte(): User
    {
        return User::creerCompteBackOffice([
            'name' => 'MFA', 'email' => uniqid('mfa.', true).'@scolarite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    public function test_login_exige_le_defi_si_mfa_active(): void
    {
        $compte = $this->compte();
        $secret = app(MfaTotpService::class)->genererSecret();
        $compte->forceFill(['mfa_secret' => $secret, 'mfa_active' => true])->save();

        $this->post(route('admin.connexion.login'), [
            'email' => $compte->email, 'password' => 'Motdepasse@123',
        ])->assertRedirect(route('admin.connexion.defi'));
        $this->assertGuest();
        $this->get(route('admin.dashboard'))->assertRedirect();

        // Mauvais code : toujours invité.
        $this->post(route('admin.connexion.defi.verifier'), ['code' => '000000'])
            ->assertSessionHasErrors('code');
        $this->assertGuest();

        // Bon code : connecté.
        $code = app(MfaTotpService::class)->calculer($secret, (int) floor(time() / 30));
        $this->post(route('admin.connexion.defi.verifier'), ['code' => $code])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticated();
    }

    public function test_login_sans_defi_si_mfa_inactive(): void
    {
        $compte = $this->compte();

        $this->post(route('admin.connexion.login'), [
            'email' => $compte->email, 'password' => 'Motdepasse@123',
        ])->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticated();
    }

    public function test_activation_desactivation_et_secours(): void
    {
        $superAdmin = User::creerCompteBackOffice([
            'name' => 'Super', 'email' => uniqid('sm.', true).'@scolarite.test',
            'password' => 'Motdepasse@123',
        ], 'super_admin');
        $compte = $this->compte();
        $this->actingAs($compte);

        // Activation : secret en session, confirmation par code.
        $this->get(route('admin.mfa.configurer'))->assertOk()
            ->assertSee('Saisissez ce secret', false);
        $secret = session('mfa_secret_attente');
        $this->assertIsString($secret);
        $this->assertNull($compte->fresh()->mfa_secret);

        $code = app(MfaTotpService::class)->calculer($secret, (int) floor(time() / 30));
        $this->post(route('admin.mfa.confirmer'), ['code' => substr($code, 0, 3).' '.substr($code, 3)])->assertRedirect();
        $this->assertTrue((bool) $compte->fresh()->mfa_active);

        // Désactivation : mot de passe erroné refusé, correct accepté.
        $this->post(route('admin.mfa.desactiver'), ['password' => 'FauxMdp123'])
            ->assertSessionHasErrors('password');
        $this->assertTrue((bool) $compte->fresh()->mfa_active);
        $this->post(route('admin.mfa.desactiver'), ['password' => 'Motdepasse@123'])
            ->assertRedirect();
        $this->assertFalse((bool) $compte->fresh()->mfa_active);

        // Porte de secours : réinitialisation super-admin coupe la MFA.
        $compte->forceFill(['mfa_secret' => 'X', 'mfa_active' => true])->save();
        $this->actingAs($superAdmin);
        $this->post(route('admin.administrateurs.reinitialiser', $compte), [
            'password' => 'NouveauMdp123', 'password_confirmation' => 'NouveauMdp123',
        ])->assertRedirect();
        $this->assertFalse((bool) $compte->fresh()->mfa_active);
        $this->assertNull($compte->fresh()->mfa_secret);
    }

    public function test_defi_sans_session_redirige(): void
    {
        $this->get(route('admin.connexion.defi'))->assertRedirect(route('admin.connexion.show'));
    }

    public function test_nouveau_secret_regenere(): void
    {
        $compte = $this->compte();
        $this->actingAs($compte);
        $this->get(route('admin.mfa.configurer'))->assertOk();
        $ancien = session('mfa_secret_attente');

        $this->post(route('admin.mfa.nouveau-secret'))->assertRedirect();
        $this->assertNotSame($ancien, session('mfa_secret_attente'));
        $this->assertNull($compte->fresh()->mfa_secret);
    }
}
