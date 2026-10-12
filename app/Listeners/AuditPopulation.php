<?php

namespace App\Listeners;

use App\Events\PopulationChanged;
use Illuminate\Support\Facades\Log;

/**
 * Audit population : ids + action + contexte, jamais de PII.
 */
class AuditPopulation
{
    public function handle(PopulationChanged $event): void
    {
        Log::info('population.'.$event->domaine.'.'.$event->action, [
            'entite_id' => $event->entiteId,
            'auteur_id' => $event->auteurId,
            'contexte' => $event->contexte,
        ]);
    }
}
