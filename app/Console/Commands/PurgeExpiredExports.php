<?php

namespace App\Console\Commands;

use App\Models\ImportReport;
use App\Models\IntegrationLog;
use App\Services\Io\ExportOrchestrator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

use function Laravel\Prompts\info;

/**
 * §82 + audit §P1-7 — Purge des fichiers expirés : exports ET imports.
 *
 * Les fichiers d'échange contiennent des données personnelles (CIN, emails,
 * adresses, notes) : au-delà de la durée de conservation configurable, ils
 * sont supprimés et la purge est journalisée.
 *
 * - EXPORTS : fichiers conservés jusqu'à expires_at (INTEGRATION_EXPORT_RETENTION_HOURS) ;
 * - IMPORTS : les rapports confirmés voient leur fichier supprimé immédiatement
 *   par ImportOrchestrator::executer() ; les rapports jamais confirmés
 *   (pending/cancelled abandonnés) sont purgés ici à l'expiration
 *   (INTEGRATION_IMPORT_RETENTION_HOURS).
 *
 * Planification : `Schedule::command('exports:purge')->hourly()` (routes/console.php).
 */
class PurgeExpiredExports extends Command
{
    protected $signature = 'exports:purge {--dry : Afficher ce qui serait purgé sans rien supprimer}';

    protected $description = 'Supprime les fichiers d\'import/export expirés (données personnelles) et journalise la purge';

    public function handle(ExportOrchestrator $exports): int
    {
        $purges = 0;

        /* ── Exports expirés ── */
        $exportsExpires = ImportReport::where('direction', 'export')
            ->where('status', 'completed')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($exportsExpires as $rapport) {
            if ($this->option('dry')) {
                $this->line("[DRY] Purge prévue (export) : {$rapport->reference} (expiré le {$rapport->expires_at->format('d/m/Y H:i')})");

                continue;
            }

            $exports->purger($rapport);
            info("Purgé (export) : {$rapport->reference}");
        }

        $purges += $exportsExpires->count();

        /* ── Imports expirés (rapports jamais confirmés — fichier encore présent) ── */
        $importsExpires = ImportReport::where('direction', 'import')
            ->whereNotNull('file_path')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($importsExpires as $rapport) {
            if ($this->option('dry')) {
                $this->line("[DRY] Purge prévue (import) : {$rapport->reference} (expiré le {$rapport->expires_at->format('d/m/Y H:i')})");

                continue;
            }

            Storage::disk('local')->delete($rapport->file_path);

            // Un import toujours « pending » ne peut plus être confirmé sans son fichier.
            $rapport->update([
                'file_path' => null,
                'status' => $rapport->status === 'pending' ? 'expired' : $rapport->status,
            ]);

            IntegrationLog::tracer(
                integration: $rapport->integration,
                direction: 'import',
                operation: 'import.expired',
                status: 'cancelled',
                errorCode: 'expired',
                externalReference: $rapport->external_reference,
            );

            info("Purgé (import) : {$rapport->reference}");
        }

        $purges += $importsExpires->count();

        if ($purges === 0) {
            $this->info('Aucun import/export expiré — rien à purger.');

            return self::SUCCESS;
        }

        $this->info(($this->option('dry') ? 'Simulation : ' : 'Terminé : ').$purges.' fichier(s) expiré(s) traité(s).');

        return self::SUCCESS;
    }
}
