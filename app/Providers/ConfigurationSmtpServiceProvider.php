<?php

namespace App\Providers;

use App\Services\ConfigurationSmtpService;
use Illuminate\Support\ServiceProvider;

/**
 * Rend la configuration SMTP de l'écran `admin.smtp` réellement
 * opérante : la ligne active pilote le mailer Laravel dès le démarrage
 * (requêtes HTTP, commandes et workers de file), sans redéploiement
 * `.env`. Sans ligne active, `.env` reste la seule source.
 */
class ConfigurationSmtpServiceProvider extends ServiceProvider
{
    public function boot(ConfigurationSmtpService $configurationSmtp): void
    {
        $configurationSmtp->appliquerActive();
    }
}
