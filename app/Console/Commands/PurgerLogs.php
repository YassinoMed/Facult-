<?php

namespace App\Console\Commands;

use App\Models\IntegrationLog;
use App\Services\ParametreService;
use Illuminate\Console\Command;

/**
 * Rétention des journaux d'échanges (§21) : purge au-delà de la
 * durée configurée. Les rapports métier (ImportReport) suivent
 * leur propre rétention (exports:purge).
 */
class PurgerLogs extends Command
{
    protected $signature = 'maintenance:purger-logs';

    protected $description = 'Purge les journaux d’intégration au-delà de la rétention.';

    public function handle(ParametreService $parametres): int
    {
        $jours = max(7, (int) $parametres->get('supervision.logs_jours', 90));

        $supprimes = IntegrationLog::where('created_at', '<', now()->subDays($jours))->delete();

        $this->info("Journaux purgés : {$supprimes} (rétention {$jours} j).");

        return self::SUCCESS;
    }
}
