<?php

namespace Tests\Feature;

use App\Models\SmtpConfiguration;
use App\Providers\ConfigurationSmtpServiceProvider;
use App\Services\ConfigurationSmtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Pilotage SMTP par l'écran admin : la ligne ACTIVE pilote réellement le
 * mailer Laravel (et non plus seulement l'affichage), sans jamais
 * exposer ni journaliser le secret.
 */
class ConfigurationSmtpPilotageTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ConfigurationSmtpService
    {
        return app(ConfigurationSmtpService::class);
    }

    /** @param array<string, mixed> $surcharge */
    private function creer(array $surcharge = []): SmtpConfiguration
    {
        return SmtpConfiguration::create(array_merge([
            'nom' => 'Principal',
            'hote' => 'smtp.univ.test',
            'port' => 465,
            'chiffrement' => 'ssl',
            'identifiant' => 'robot',
            'mot_de_passe' => 'secret-smtp',
            'expediteur' => 'noreply@univ.test',
            'nom_expediteur' => 'Scolarité',
            'actif' => true,
        ], $surcharge));
    }

    public function test_ligne_active_pilote_reellement_le_mailer(): void
    {
        $this->creer();

        $this->assertTrue($this->service()->appliquerActive());

        // Une ligne active s'impose au `.env` (ici `array` en test).
        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.univ.test', config('mail.mailers.smtp.host'));
        $this->assertSame(465, config('mail.mailers.smtp.port'));
        $this->assertSame('robot', config('mail.mailers.smtp.username'));
        $this->assertSame('secret-smtp', config('mail.mailers.smtp.password'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame('noreply@univ.test', config('mail.from.address'));
        $this->assertSame('Scolarité', config('mail.from.name'));

        // Durcissements : certificat vérifié, attente bornée.
        $this->assertTrue(config('mail.mailers.smtp.verify_peer'));
        $this->assertSame(ConfigurationSmtpService::DELAI_MAX, config('mail.mailers.smtp.timeout'));
    }

    public function test_aucune_ligne_active_laisse_le_env_en_vigueur(): void
    {
        $hote = config('mail.mailers.smtp.host');

        $this->assertFalse($this->service()->appliquerActive());

        $this->assertSame($hote, config('mail.mailers.smtp.host'));
        $this->assertSame('array', config('mail.default')); // valeur phpunit.xml
    }

    public function test_chiffrements_mappes_vers_le_schema_de_transport(): void
    {
        $configuration = $this->creer(['chiffrement' => 'tls']);
        $service = $this->service();

        // STARTTLS exigé : jamais de repli en clair avec identifiants.
        $this->assertTrue($service->appliquer($configuration));
        $this->assertSame('smtp', config('mail.mailers.smtp.scheme'));
        $this->assertTrue(config('mail.mailers.smtp.auto_tls'));
        $this->assertTrue(config('mail.mailers.smtp.require_tls'));

        // Relais local sans chiffrement : assumé, jamais négocié.
        $configuration->update(['chiffrement' => 'aucun']);
        $this->assertTrue($service->appliquer($configuration->fresh()));
        $this->assertFalse(config('mail.mailers.smtp.auto_tls'));
        $this->assertFalse(config('mail.mailers.smtp.require_tls'));

        // TLS implicite : le schéma suffit.
        $configuration->update(['chiffrement' => 'ssl']);
        $this->assertTrue($service->appliquer($configuration->fresh()));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertFalse(config('mail.mailers.smtp.require_tls'));
    }

    public function test_champs_optionnels_vides_ne_surchargent_rien(): void
    {
        $nom = config('mail.from.name');
        $this->creer(['identifiant' => null, 'mot_de_passe' => null, 'nom_expediteur' => null]);

        $this->assertTrue($this->service()->appliquerActive());

        $this->assertNull(config('mail.mailers.smtp.username'));
        $this->assertNull(config('mail.mailers.smtp.password'));
        $this->assertSame($nom, config('mail.from.name'));
    }

    public function test_secret_indechiffrable_est_ignore_sans_fuite(): void
    {
        $configuration = $this->creer();
        // Charge volontairement illisible (clé APP_KEY changée, valeur corrompue).
        DB::table('smtp_configurations')
            ->where('id', $configuration->id)
            ->update(['mot_de_passe' => 'pas-du-chiffre']);

        Log::spy();
        $hote = config('mail.mailers.smtp.host');

        $this->assertFalse($this->service()->appliquerActive());

        // Aucune écriture partielle : le `.env` reste en vigueur.
        $this->assertSame($hote, config('mail.mailers.smtp.host'));

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $contexte): bool => $message === 'smtp.configuration_indechiffrable'
                && $contexte === ['configuration_id' => $configuration->id]
                && ! in_array('pas-du-chiffre', $contexte, true)
        )->once();
    }

    public function test_base_indisponible_ne_perturbe_pas_le_repli(): void
    {
        $origine = config('database.default');

        config([
            'database.connections.base_absente' => [
                'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1,
                'database' => 'absente', 'username' => 'x', 'password' => 'x',
            ],
            'database.default' => 'base_absente',
        ]);

        try {
            $hote = config('mail.mailers.smtp.host');

            // Table inaccessible : repli silencieux, aucune exception.
            $this->assertFalse($this->service()->appliquerActive());
            $this->assertSame($hote, config('mail.mailers.smtp.host'));
        } finally {
            config(['database.default' => $origine]);
        }
    }

    public function test_provider_enregistre_puis_applique_au_demarrage(): void
    {
        $this->assertArrayHasKey(ConfigurationSmtpServiceProvider::class, $this->app->getLoadedProviders());

        $this->creer(['hote' => 'smtp.pilote.test', 'chiffrement' => 'tls']);

        // Le démarrage réel (boot) applique la ligne active.
        (new ConfigurationSmtpServiceProvider($this->app))->boot(app(ConfigurationSmtpService::class));

        $this->assertSame('smtp.pilote.test', config('mail.mailers.smtp.host'));
        $this->assertSame('smtp', config('mail.default'));
    }
}
