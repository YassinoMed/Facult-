<?php

namespace Tests\Feature;

use App\Models\SmtpConfiguration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP J — SMTP UI : CRUD, secret conservé si vide, test de connexion
 * honnête, super_admin seul.
 */
class SmtpUiLoopJTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): void
    {
        $user = User::creerCompteBackOffice([
            'name' => 'Super', 'email' => uniqid('sj.', true).'@scolarite.test',
            'password' => 'Motdepasse@123',
        ], 'super_admin');
        $this->actingAs($user);
    }

    private function donnees(array $surcharge = []): array
    {
        return array_merge([
            'nom' => 'Test', 'hote' => '127.0.0.1', 'port' => 1,
            'chiffrement' => 'aucun', 'identifiant' => null,
            'mot_de_passe' => 'secret-smtp', 'expediteur' => 'noreply@univ.test',
            'nom_expediteur' => null, 'actif' => false,
        ], $surcharge);
    }

    public function test_crud_et_secret_conserve(): void
    {
        $this->superAdmin();

        $this->post(route('admin.smtp.store'), $this->donnees())->assertRedirect();
        $config = SmtpConfiguration::where('nom', 'Test')->firstOrFail();
        $this->assertSame('secret-smtp', $config->mot_de_passe);

        // Mot de passe vide = conservé, jamais réaffiché.
        $this->put(route('admin.smtp.update', $config), $this->donnees(['mot_de_passe' => null, 'hote' => 'smtp2.test']))
            ->assertRedirect();
        $this->assertSame('secret-smtp', $config->fresh()->mot_de_passe);
        $this->get(route('admin.smtp.edit', $config))->assertOk()
            ->assertDontSee('secret-smtp');

        $this->delete(route('admin.smtp.destroy', $config))->assertRedirect();
        $this->assertSame(0, SmtpConfiguration::count());
    }

    public function test_connexion_impossible_rapporte_sans_secret(): void
    {
        $this->superAdmin();
        $config = SmtpConfiguration::create($this->donnees(['hote' => '127.0.0.1', 'port' => 1]));

        $this->post(route('admin.smtp.tester', $config))
            ->assertRedirect()
            ->assertSessionHasErrors('connexion');
        $this->assertDatabaseHas('smtp_configurations', ['id' => $config->id]);
    }

    public function test_reserve_super_admin(): void
    {
        $agent = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => uniqid('aj.', true).'@scolarite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->actingAs($agent);

        $this->get(route('admin.smtp.index'))->assertForbidden();
        $this->post(route('admin.smtp.store'), $this->donnees())->assertForbidden();
    }

    public function test_validation_rejette(): void
    {
        $this->superAdmin();

        $this->post(route('admin.smtp.store'), $this->donnees(['port' => 99999, 'expediteur' => 'pas-un-email']))
            ->assertSessionHasErrors(['port', 'expediteur']);
        $this->assertSame(0, SmtpConfiguration::count());
    }
}
