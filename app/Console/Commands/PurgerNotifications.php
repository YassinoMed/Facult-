<?php

namespace App\Console\Commands;

use App\Services\NotificationService;
use App\Services\ParametreService;
use Illuminate\Console\Command;

/**
 * Purge hebdomadaire des notifications lues anciennes (§40).
 */
class PurgerNotifications extends Command
{
    protected $signature = 'notifications:purger {--jours= : Ancienneté minimale des notifications lues (défaut : paramètre notifications.purge_jours)}';

    protected $description = 'Purge les notifications lues anciennes.';

    public function handle(NotificationService $notifications, ParametreService $parametres): int
    {
        // LOOP E : le paramètre pilote le défaut, l'option explicite prime.
        $jours = $this->option('jours') !== null
            ? (int) $this->option('jours')
            : max(1, (int) $parametres->get('notifications.purge_jours', 180));

        $supprimees = $notifications->purgerLues($jours);

        $this->info("Notifications purgées : {$supprimees}.");

        return self::SUCCESS;
    }
}
