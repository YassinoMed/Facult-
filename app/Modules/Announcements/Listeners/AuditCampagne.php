<?php

namespace App\Modules\Announcements\Listeners;

use App\Modules\Announcements\Events\CampagneEnvoyee;
use Illuminate\Support\Facades\Log;

class AuditCampagne
{
    public function handle(CampagneEnvoyee $event): void
    {
        Log::info('campagne.envoyee', ['campagne_id' => $event->campagneId, 'acteur_id' => auth()->id()]);
    }
}
