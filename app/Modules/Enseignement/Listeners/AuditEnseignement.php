<?php

namespace App\Modules\Enseignement\Listeners;

use App\Modules\Enseignement\Events\EnseignementChanged;
use Illuminate\Support\Facades\Log;

/**
 * Audit des enseignements : ids + action + contexte, sans PII.
 */
class AuditEnseignement
{
    public function handle(EnseignementChanged $event): void
    {
        Log::info('enseignement.'.$event->action, [
            'enseignement_id' => $event->enseignementId,
            'auteur_id' => $event->auteurId,
            'contexte' => $event->contexte,
        ]);
    }
}
