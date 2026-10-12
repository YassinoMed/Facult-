<?php

namespace App\Modules\Referentiel\Listeners;

use App\Modules\Referentiel\Events\MaquetteTransitionnee;
use Illuminate\Support\Facades\Log;

/**
 * Audit des transitions de maquettes : ids + transition uniquement.
 */
class AuditMaquette
{
    public function handle(MaquetteTransitionnee $event): void
    {
        Log::info('referentiel.maquette.'.$event->transition, [
            'maquette_id' => $event->maquette->id,
            'programme_id' => $event->maquette->programme_id,
            'statut' => $event->maquette->statut,
            'auteur_id' => $event->auteurId,
        ]);
    }
}
