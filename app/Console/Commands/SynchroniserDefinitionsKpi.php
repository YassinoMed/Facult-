<?php

namespace App\Console\Commands;

use App\Services\BIService;
use Illuminate\Console\Command;

/**
 * LOT 03 — Synchronisation du registre persistant des définitions KPI.
 * Alimente la table `kpi_definitions` à partir des catalogues de code
 * (BI centrale + catalogue du cockpit) : dérive documentaire versionnée
 * + snapshot de valeur du périmètre BI. Idempotent, descriptive
 * uniquement — jamais d'écriture métier. Fail-soft : registre absent
 * (migration non appliquée) = avertissement, pas d'erreur.
 */
class SynchroniserDefinitionsKpi extends Command
{
    protected $signature = 'bi:synchroniser-kpi-definitions';

    protected $description = 'Versionne et figure les définitions KPI (BI + cockpit) dans le registre persistant.';

    public function handle(BIService $bi): int
    {
        $resultat = $bi->synchroniserDefinitions();

        if (! ($resultat['actif'] ?? false)) {
            $this->warn((string) ($resultat['raison'] ?? 'Registre KPI indisponible.'));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Registre KPI : %d créé(s), %d mis à jour (dérive documentaire), %d snapshot(s) chiffré(s), %d cockpit, %d BI.',
            $resultat['creees'],
            $resultat['mises_a_jour'],
            $resultat['figurees'],
            $resultat['cockpit'],
            $resultat['bi_service'],
        ));

        return self::SUCCESS;
    }
}
