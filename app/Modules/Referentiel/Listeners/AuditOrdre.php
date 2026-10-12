<?php

namespace App\Modules\Referentiel\Listeners;

use App\Modules\Referentiel\Events\OrdreModifie;
use Illuminate\Support\Facades\Log;

class AuditOrdre
{
    public function handle(OrdreModifie $event): void
    {
        Log::info('referentiel.ordre', [
            'type' => $event->type,
            'nombre' => $event->nombre,
            'acteur_id' => $event->auteurId,
        ]);
    }
}
