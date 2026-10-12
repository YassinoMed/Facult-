<?php

namespace App\Console\Commands;

use App\Modules\OnlineRequests\Services\SlaService;
use Illuminate\Console\Command;

/**
 * Escalade SLA quotidienne (§38) : notifie les agents des demandes
 * en retard imputable (hors pause waiting_student).
 */
class VerifierSlaDemandes extends Command
{
    protected $signature = 'demandes:verifier-sla';

    protected $description = 'Escalade les demandes en retard de traitement (SLA).';

    public function handle(SlaService $sla): int
    {
        $resultat = $sla->verifierEtEscalader();

        $this->info("Vérifiées : {$resultat['verifiees']}, escaladées : {$resultat['escaladees']}.");

        return self::SUCCESS;
    }
}
