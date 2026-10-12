<?php

namespace App\Console\Commands;

use App\Modules\Announcements\Models\Campagne;
use App\Modules\Announcements\Services\CampagneService;
use Illuminate\Console\Command;

/**
 * Envoi des campagnes planifiées échues (§15.6/15.14) : idempotent
 * (statut + déduplication par campagne).
 */
class EnvoyerCampagnes extends Command
{
    protected $signature = 'campagnes:envoyer';

    protected $description = 'Envoie les campagnes planifiées dont la date est atteinte.';

    public function handle(CampagneService $campagnes): int
    {
        $envoyees = 0;

        foreach (Campagne::where('statut', 'planifiee')->where('planifiee_a', '<=', now())->cursor() as $campagne) {
            $campagnes->envoyer($campagne);
            $envoyees++;
        }

        $this->info("Campagnes envoyées : {$envoyees}.");

        return self::SUCCESS;
    }
}
