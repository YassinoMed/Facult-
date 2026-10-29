<?php

namespace App\Services;

use App\Models\SmtpConfiguration;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pilotage SMTP (LOOP J) : applique à Laravel la configuration SMTP
 * ACTIVE enregistrée en base par l'écran `admin.smtp`. Sans ligne
 * active, `.env` reste la seule source (repli silencieux).
 *
 * Garanties :
 * - un secret n'est jamais journalisé : seuls l'identifiant de ligne
 *   et la classe d'exception peuvent apparaître ;
 * - une ligne indéchiffrable (clé `APP_KEY` changée, valeur corrompue)
 *   n'est jamais appliquée : repli sur `.env` + trace sans secret ;
 * - le chiffrement choisi devient un SCHÉMA DE TRANSPORT explicite :
 *   `ssl` = TLS implicite (`smtps`), `tls` = STARTTLS **exigé**
 *   (`require_tls`, jamais de repli en clair avec identifiants),
 *   `aucun` = relais local sans chiffrement, assumé explicitement ;
 * - la vérification du certificat serveur n'est jamais désactivée ;
 * - le délai d'attente est borné : une panne SMTP ne peut pas figer
 *   une requête publique au-delà de ce plafond (les envois OTP sont
 *   synchrones dans la réponse HTTP).
 */
class ConfigurationSmtpService
{
    /** Plafond d'attente du dialogue SMTP, en secondes. */
    public const DELAI_MAX = 10;

    /**
     * Applique la configuration active si elle existe.
     *
     * @return bool true si une configuration a réellement été appliquée.
     */
    public function appliquerActive(): bool
    {
        try {
            $configuration = SmtpConfiguration::where('actif', true)->first();
        } catch (Throwable) {
            // Table absente (migrations non jouées) ou base indisponible :
            // la configuration `.env` reste en vigueur, sans bruit.
            return false;
        }

        if ($configuration === null) {
            return false; // aucune ligne active : `.env` fait foi
        }

        return $this->appliquer($configuration);
    }

    /**
     * Applique une configuration donnée au mailer Laravel.
     *
     * @return bool false si la configuration est inexploitable (hôte vide
     *              ou secret indéchiffrable) — aucune écriture partielle.
     */
    public function appliquer(SmtpConfiguration $configuration): bool
    {
        if (trim((string) $configuration->hote) === '') {
            return false;
        }

        try {
            // Cast `encrypted` : lève si la charge en base est illisible.
            $secret = (string) $configuration->mot_de_passe;
        } catch (Throwable) {
            Log::warning('smtp.configuration_indechiffrable', [
                'configuration_id' => $configuration->id,
            ]);

            return false;
        }

        $chiffrement = (string) $configuration->chiffrement;
        $tlsImplicite = $chiffrement === 'ssl';

        config([
            // Activer une ligne = envoyer par elle, quel que soit MAIL_MAILER.
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $configuration->hote,
            'mail.mailers.smtp.port' => (int) $configuration->port,
            'mail.mailers.smtp.username' => $configuration->identifiant ?: null,
            'mail.mailers.smtp.password' => $secret !== '' ? $secret : null,
            'mail.mailers.smtp.scheme' => $tlsImplicite ? 'smtps' : 'smtp',
            // STARTTLS non tenté pour un relais local explicitement sans
            // chiffrement ; sinon il est exigé (aucun repli en clair).
            'mail.mailers.smtp.auto_tls' => $chiffrement !== 'aucun',
            'mail.mailers.smtp.require_tls' => $chiffrement === 'tls',
            // Vérification du certificat serveur : jamais désactivée.
            'mail.mailers.smtp.verify_peer' => true,
            'mail.mailers.smtp.timeout' => self::DELAI_MAX,
        ]);

        if (trim((string) $configuration->expediteur) !== '') {
            config([
                'mail.from.address' => $configuration->expediteur,
                'mail.from.name' => $configuration->nom_expediteur ?: config('mail.from.name'),
            ]);
        }

        return true;
    }
}
