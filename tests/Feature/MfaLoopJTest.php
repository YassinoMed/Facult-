<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MfaRecoveryCodeService;
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

    protected function setUp(): void
    {
        parent::setUp();

        // Politique épinglée (hermétique au .env de la machine) :
        // le double facteur est obligatoire pour super_admin uniquement.
        config()->set('security.mfa_required_roles', ['super_admin']);
    }

    private function compte(string $role = 'admin_scolarite'): User
    {
        return User::creerCompteBackOffice([
            'name' => 'MFA', 'email' => uniqid('mfa.', true).'@scolarite.test',
            'password' => 'Motdepasse@123',
        ], $role);
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
        // Rôle non-privilégié : pas de défi tant que la MFA reste
        // facultative (les rôles à privilèges sont couverts plus bas).
        $compte = $this->compte('agent_scolarite');

        $this->post(route('admin.connexion.login'), [
            'email' => $compte->email, 'password' => 'Motdepasse@123',
        ])->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticated();
    }

    public function test_login_role_priviligie_sans_mfa_force_l_enrolement(): void
    {
        // super_admin : rôle à privilèges de la politique courante.
        $compte = $this->compte('super_admin');

        // Mot de passe valide mais aucun TOTP enrôlé : l'accès au
        // back-office est remplacé par l'enrôlement obligatoire.
        $this->post(route('admin.connexion.login'), [
            'email' => $compte->email, 'password' => 'Motdepasse@123',
        ])->assertRedirect(route('admin.mfa.configurer'));
        $this->assertAuthenticated();

        // Toute page du back-office renvoie vers l'enrôlement.
        $this->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.mfa.configurer'));

        // Activation complète (code + mot de passe) → accès rétabli.
        $this->get(route('admin.mfa.configurer'))->assertOk();
        $secret = session('mfa_secret_attente');
        $code = app(MfaTotpService::class)->calculer($secret, (int) floor(time() / 30));
        $this->post(route('admin.mfa.confirmer'), [
            'code' => $code, 'password' => 'Motdepasse@123',
        ])->assertRedirect(route('admin.mfa.configurer'));

        $this->assertTrue((bool) $compte->fresh()->mfa_active);
        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_activation_exige_le_mot_de_passe(): void
    {
        $compte = $this->compte('agent_scolarite');
        $this->actingAs($compte);

        $this->get(route('admin.mfa.configurer'))->assertOk();
        $secret = session('mfa_secret_attente');
        $code = app(MfaTotpService::class)->calculer($secret, (int) floor(time() / 30));

        // Mauvais mot de passe : pas d'activation.
        $this->post(route('admin.mfa.confirmer'), ['code' => $code, 'password' => 'FauxMdp123'])
            ->assertSessionHasErrors('password');
        $this->assertFalse((bool) $compte->fresh()->mfa_active);

        $this->post(route('admin.mfa.confirmer'), ['code' => $code, 'password' => 'Motdepasse@123'])
            ->assertRedirect(route('admin.mfa.configurer'));
        $this->assertTrue((bool) $compte->fresh()->mfa_active);
    }

    public function test_codes_de_secours_a_usage_unique(): void
    {
        $compte = $this->compte();
        $secret = app(MfaTotpService::class)->genererSecret();
        $compte->forceFill(['mfa_secret' => $secret, 'mfa_active' => true])->save();

        // Activation volontaire via le contrôleur pour récupérer les codes.
        $this->actingAs($compte);
        $codes = app(MfaRecoveryCodeService::class)->generer($compte);
        $this->assertCount(8, $codes);

        // Déconnexion, puis connexion par code de secours (usage unique).
        auth()->logout();
        $this->post(route('admin.connexion.login'), [
            'email' => $compte->email, 'password' => 'Motdepasse@123',
        ])->assertRedirect(route('admin.connexion.defi'));

        $this->post(route('admin.connexion.defi.verifier'), ['code' => $codes[0]])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticated();

        // Le même code ne peut pas resservir.
        auth()->logout();
        $this->post(route('admin.connexion.login'), [
            'email' => $compte->email, 'password' => 'Motdepasse@123',
        ])->assertRedirect(route('admin.connexion.defi'));
        $this->post(route('admin.connexion.defi.verifier'), ['code' => $codes[0]])
            ->assertSessionHasErrors('code');
        $this->assertGuest();

        // Code de secours suivant : toujours valide.
        $this->post(route('admin.connexion.defi.verifier'), ['code' => $codes[1]])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticated();
        $this->assertSame(6, app(MfaRecoveryCodeService::class)->restants($compte->fresh()));
    }

    public function test_desactivation_refusee_pour_role_a_privileges(): void
    {
        // super_admin : rôle à privilèges de la politique courante.
        $compte = $this->compte('super_admin');
        $compte->forceFill([
            'mfa_secret' => app(MfaTotpService::class)->genererSecret(),
            'mfa_active' => true,
        ])->save();

        $this->actingAs($compte);
        $this->post(route('admin.mfa.desactiver'), ['password' => 'Motdepasse@123'])
            ->assertSessionHasErrors('password');
        $this->assertTrue((bool) $compte->fresh()->mfa_active);
    }

    public function test_activation_desactivation_et_secours(): void
    {
        $superAdmin = User::creerCompteBackOffice([
            'name' => 'Super', 'email' => uniqid('sm.', true).'@scolarite.test',
            'password' => 'Motdepasse@123',
        ], 'super_admin');
        $compte = $this->compte();
        $this->actingAs($compte);

        // Activation : secret en session, confirmation par code + mot de passe.
        $this->get(route('admin.mfa.configurer'))->assertOk()
            ->assertSee('saisissez ce secret', false);
        $secret = session('mfa_secret_attente');
        $this->assertIsString($secret);
        $this->assertNull($compte->fresh()->mfa_secret);

        $code = app(MfaTotpService::class)->calculer($secret, (int) floor(time() / 30));
        $this->post(route('admin.mfa.confirmer'), [
            'code' => substr($code, 0, 3).' '.substr($code, 3),
            'password' => 'Motdepasse@123',
        ])->assertRedirect();
        $this->assertTrue((bool) $compte->fresh()->mfa_active);

        // Codes de secours : affichés une seule fois (flash), stockés hachés.
        $codes = session('codes_secours');
        $this->assertIsArray($codes);
        $this->assertCount(8, $codes);
        $this->assertSame(8, app(MfaRecoveryCodeService::class)->restants($compte->fresh()));

        // Désactivation autorisée pour un rôle non requis par la
        // politique courante (super_admin seul) : bon mot de passe → OK.
        $this->post(route('admin.mfa.desactiver'), ['password' => 'Motdepasse@123'])
            ->assertRedirect(route('admin.mfa.configurer'));
        $this->assertFalse((bool) $compte->fresh()->mfa_active);

        // Ré-armement silencieux pour éprouver la porte de secours.
        $compte->forceFill([
            'mfa_secret' => app(MfaTotpService::class)->genererSecret(),
            'mfa_active' => true,
        ])->save();

        // Porte de secours : réinitialisation super-admin coupe la MFA
        // (et les codes de secours associés).
        $this->actingAs($superAdmin);
        $this->post(route('admin.administrateurs.reinitialiser', $compte), [
            'password' => 'NouveauMdp123', 'password_confirmation' => 'NouveauMdp123',
        ])->assertRedirect();
        $this->assertFalse((bool) $compte->fresh()->mfa_active);
        $this->assertNull($compte->fresh()->mfa_secret);
        $this->assertNull($compte->fresh()->mfa_recovery_codes);
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

    public function test_configurer_affiche_qr_reel_et_saisie_manuelle(): void
    {
        $compte = $this->compte();
        $this->actingAs($compte);

        // QR réel (libellé locale) + secret manuel + URI otpauth.
        $this->get(route('admin.mfa.configurer'))->assertOk()
            ->assertSee('mfa-qr', false)
            ->assertSee('qrcode-generator.min.js', false)
            ->assertSee('otpauth://totp/', false)
            ->assertSee('data-copier="secret"', false);
    }
}
