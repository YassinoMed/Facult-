<?php

namespace App\Console\Commands;

use App\Modules\Finances\Services\FinancialReconciliationService;
use Illuminate\Console\Command;

/**
 * Rapprochement financier en lecture seule. Aucune validation, aucun
 * paiement, aucune correction : le rapport sert à investiguer.
 */
class AuditRapprochementFinancier extends Command
{
    protected $signature = 'finances:audit-rapprochement
                            {--json : Sortie JSON exploitable}
                            {--strict : Code retour 1 si au moins une anomalie est détectée}';

    protected $description = 'Audite dossiers/reçus (doublons, états contradictoires, orphelins) sans modifier les données.';

    public function handle(FinancialReconciliationService $service): int
    {
        $rapport = $service->rapport();

        if ($this->option('json')) {
            $this->line((string) json_encode($rapport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->warn('MODE LECTURE SEULE — aucun paiement n’est confirmé et aucune donnée n’est corrigée.');
            $this->line('Dossiers : '.$rapport['volumes']['dossiers']
                .' · Reçus : '.$rapport['volumes']['recus']
                .' · Reçus validés : '.$rapport['volumes']['recus_valides']);

            $this->table(
                ['Anomalie', 'Nombre'],
                collect($rapport['anomalies'])
                    ->map(fn (array $anomalie, string $cle): array => [$cle, $anomalie['total']])
                    ->values()
                    ->all(),
            );

            $this->line('Total anomalies : '.$rapport['nombre_anomalies']);
            $this->line($rapport['avertissement']);
        }

        if ($this->option('strict') && $rapport['nombre_anomalies'] > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
