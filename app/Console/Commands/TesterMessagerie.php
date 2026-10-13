<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * E-mail de test (Phase C) : valide le destinataire et le pilote,
 * n'affiche jamais de secret, ne prétend jamais un envoi réseau
 * lorsque le pilote est local (`log`, `array`).
 */
class TesterMessagerie extends Command
{
    protected $signature = 'mail:tester {destinataire : Adresse e-mail autorisée du test} {--sujet=Test UniRegistre}';

    protected $description = 'Envoie un e-mail de test honnête (diagnostic du pilote, sans secret affiché).';

    public function handle(): int
    {
        $destinataire = (string) $this->argument('destinataire');

        if (! filter_var($destinataire, FILTER_VALIDATE_EMAIL)) {
            $this->error('Destinataire invalide.');

            return self::INVALID;
        }

        $pilote = (string) config('mail.default');

        if (! in_array($pilote, ['smtp', 'sendmail', 'log', 'array'], true)) {
            $this->error("Pilote « {$pilote} » non pris en charge pour le test.");

            return self::FAILURE;
        }

        try {
            Mail::raw(
                'E-mail de test UniRegistre émis le '.now()->format('d/m/Y H:i').'.',
                fn ($message) => $message->to($destinataire)->subject((string) $this->option('sujet'))
            );
        } catch (Throwable $e) {
            // Détail expurgé : jamais de credential ni de trace complète.
            Log::channel(config('logging.default'))->warning('mail.test_echec', [
                'pilote' => $pilote,
                'classe' => $e::class,
            ]);
            $this->error('Échec d\'envoi (voir journal applicatif).');

            return self::FAILURE;
        }

        if (in_array($pilote, ['log', 'array'], true)) {
            $this->info("Enregistré via le pilote « {$pilote} » — aucun envoi réseau effectué.");
        } else {
            $this->info("Transmis au pilote « {$pilote} » pour envoi.");
        }

        return self::SUCCESS;
    }
}
