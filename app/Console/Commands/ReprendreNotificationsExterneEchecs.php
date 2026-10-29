<?php

namespace App\Console\Commands;

use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * LOT 02 — outbox des notifications : scan des envois externes en échec
 * (`echec`, `echec_definitif`) et des `en_attente` oubliés, puis replanification.
 * Idempotent : les `envoye` ne sont JAMAIS repris. Sûr quel que soit le
 * nombre de passages (débit borné par la fenêtre de staleness).
 */
class ReprendreNotificationsExterneEchecs extends Command
{
    protected $signature = 'notifications:reprendre-echecs {--limite= : Nombre maximum de reprises par passage (défaut : paramètre communication.reprise.limite_par_scan)}';

    protected $description = 'Reprise des envois externes de notifications en échec ou perdus (outbox).';

    public function handle(NotificationService $notifications): int
    {
        $limite = $this->option('limite') !== null ? (int) $this->option('limite') : null;
        $reprises = $notifications->reprendreEchecsExterieurs($limite);

        $this->info("{$reprises} envoi(s) externe(s) replanifié(s) pour reprise.");

        return self::SUCCESS;
    }
}
