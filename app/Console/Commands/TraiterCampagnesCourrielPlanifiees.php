<?php

namespace App\Console\Commands;

use App\Models\CampagneCourriel;
use App\Services\CampagneCourrielService;
use Illuminate\Console\Command;

/**
 * Commande de traitement des campagnes de courriels planifiées arrivées à échéance (LOOP-MAIL-CAMPAIGNS-01).
 * Idempotente : vérifie le statut `planifiee` et `planifiee_a <= now()`.
 */
class TraiterCampagnesCourrielPlanifiees extends Command
{
    protected $signature = 'campagnes-courriel:traiter-planifiees';

    protected $description = 'Déclenche l’envoi des campagnes de courriels planifiées dont l’échéance est atteinte.';

    public function handle(CampagneCourrielService $service): int
    {
        $aTraiter = CampagneCourriel::where('statut', CampagneCourriel::STATUT_PLANIFIEE)
            ->whereNotNull('planifiee_a')
            ->where('planifiee_a', '<=', now())
            ->get();

        $compteur = 0;

        foreach ($aTraiter as $campagne) {
            $this->info("Déclenchement de la campagne #{$campagne->id} : {$campagne->titre}");
            $service->envoyer($campagne);
            $compteur++;
        }

        $this->info("{$compteur} campagne(s) de courriel(s) déclenchée(s).");

        return self::SUCCESS;
    }
}
