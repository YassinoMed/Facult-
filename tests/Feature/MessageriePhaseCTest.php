<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase C — Messagerie : diagnostic honnête, validation, sans secret.
 */
class MessageriePhaseCTest extends TestCase
{
    use RefreshDatabase;

    public function test_destinataire_invalide_refuse(): void
    {
        $this->artisan('mail:tester', ['destinataire' => 'pas-un-email'])
            ->assertFailed();
    }

    public function test_pilote_local_honnete_sans_envoi_reseau(): void
    {
        config(['mail.default' => 'log']);

        $this->artisan('mail:tester', ['destinataire' => 'test@example.com'])
            ->expectsOutputToContain('aucun envoi réseau')
            ->assertSuccessful();
    }

    public function test_diagnostic_sans_secret(): void
    {
        $superAdmin = User::creerCompteBackOffice([
            'name' => 'Super', 'email' => uniqid('mc.', true).'@scolarite.test',
            'password' => 'Motdepasse@123',
        ], 'super_admin');
        $this->actingAs($superAdmin);

        $contenu = $this->get(route('admin.parametres.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Courriel', $contenu);
        $this->assertStringNotContainsString('MAIL_PASSWORD', $contenu);
        $this->assertStringNotContainsString('mot de passe SMTP', $contenu);
    }
}
