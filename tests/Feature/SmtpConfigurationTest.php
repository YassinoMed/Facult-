<?php

namespace Tests\Feature;

use App\Models\SmtpConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ORM SMTP : chiffrement au repos, masquage, activation unique.
 */
class SmtpConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_mot_de_passe_chiffre_et_masque(): void
    {
        $config = SmtpConfiguration::create([
            'nom' => 'Principal', 'hote' => 'smtp.univ.test', 'port' => 587,
            'chiffrement' => 'tls', 'identifiant' => 'robot',
            'mot_de_passe' => 'secret-smtp', 'expediteur' => 'noreply@univ.test',
            'actif' => true,
        ]);

        // En base : jamais en clair.
        $brut = DB::table('smtp_configurations')
            ->where('id', $config->id)->value('mot_de_passe');
        $this->assertStringNotContainsString('secret-smtp', (string) $brut);
        // Déchiffrement applicatif OK.
        $this->assertSame('secret-smtp', $config->fresh()->mot_de_passe);
        // Masqué en sérialisation.
        $this->assertArrayNotHasKey('mot_de_passe', $config->fresh()->toArray());
    }

    public function test_activation_unique(): void
    {
        $premiere = SmtpConfiguration::create([
            'nom' => 'Une', 'hote' => 'a.test', 'expediteur' => 'a@test.tn', 'actif' => true,
        ]);
        $seconde = SmtpConfiguration::create([
            'nom' => 'Deux', 'hote' => 'b.test', 'expediteur' => 'b@test.tn', 'actif' => true,
        ]);

        $this->assertFalse($premiere->fresh()->actif);
        $this->assertTrue($seconde->fresh()->actif);
        $this->assertSame(1, SmtpConfiguration::where('actif', true)->count());
    }
}
