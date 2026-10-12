<?php

namespace App\Services\Io;

use App\Jobs\Io\ExportAcademicRecordsJob;
use App\Jobs\Io\ExportStudentsJob;
use App\Models\ImportReport;
use App\Models\IntegrationLog;
use App\Models\User;
use App\Services\Io\Exports\AcademicExportEngine;
use App\Services\Io\Exports\ExportFilters;
use Illuminate\Support\Facades\Storage;

/**
 * Orchestrateur des exports (§41-43, §80-83) :
 * - moteur unique multicritère (§90) ;
 * - filtres en liste blanche (§84) + colonnes personnalisables (§78) ;
 * - profils restreints / permission exports.personal (§85) ;
 * - sérialisation protégée (CSV/XLSX anti formula-injection §62-63) ;
 * - volumétries : synchrones ou job (§52) ;
 * - expiration configurable des fichiers (§82) ;
 * - journalisation systématique (§83) ; fichier en disque privé (§81).
 */
class ExportOrchestrator
{
    public function __construct(
        private readonly AcademicExportEngine $engine,
    ) {}

    /**
     * Crée le rapport d'export et génère le fichier (sync ou job §80).
     *
     * @param  array<string, mixed>  $filtres
     * @param  array<int, string>|null  $colonnes
     */
    public function preparer(
        string $type,
        string $format,
        string $profil,
        array $filtres,
        int|string|null $userId,
        string $integration = 'api',
        ?array $colonnes = null,
        ?string $granularite = null,
        ?User $utilisateur = null,
    ): ImportReport {
        if (! in_array($type, ExportFilters::TYPES, true)) {
            throw IoException::malforme("type d'export « {$type} » inconnu (".implode('|', ExportFilters::TYPES).').');
        }

        if (! IoFactory::formatValide($format)) {
            throw IoException::formatInvalide($format);
        }

        // Whitelist stricte (§84) : seules les clés autorisées survivent
        $filtres = ExportFilters::epurer($filtres);

        $rapport = ImportReport::create([
            'user_id' => $userId,
            'integration' => $integration,
            'direction' => 'export',
            'type' => $type,
            'format' => $format,
            'external_reference' => 'EXP-'.now()->format('Ymd-His'),
            'status' => 'processing',
            'preview' => ['profil' => $profil, 'filtres' => $filtres, 'columns' => $colonnes, 'granularite' => $granularite],
            'expires_at' => now()->addHours((int) config('integrations.export_retention_hours', 168)),
        ]);

        // Estimation du volume : volumineux → job (§80)
        $estimation = $this->engine->construire($type, $profil, $filtres, $utilisateur, $colonnes, null, $granularite);

        if ($estimation['total'] > (int) config('integrations.queue_threshold', 500)) {
            match ($type) {
                'students' => ExportStudentsJob::dispatch($rapport),
                'grades' => ExportAcademicRecordsJob::dispatch($rapport),
                default => ExportAcademicRecordsJob::dispatch($rapport),
            };

            return $rapport->fresh();
        }

        return $this->executer($rapport, $utilisateur);
    }

    /**
     * Exécution réelle : moteur + sérialisation + stockage privé.
     */
    public function executer(ImportReport $rapport, ?User $utilisateur = null): ImportReport
    {
        $debut = microtime(true);

        try {
            $parametres = $rapport->preview ?? [];

            $donnees = $this->engine->construire(
                type: $rapport->type,
                profil: $parametres['profil'] ?? 'administratif',
                filtres: $parametres['filtres'] ?? [],
                utilisateur: $utilisateur,
                colonnes: $parametres['columns'] ?? null,
                granularite: $parametres['granularite'] ?? null,
            );

            $contenu = IoFactory::serializerPour($rapport->format)
                ->serialize($donnees['entetes'], $donnees['lignes']);

            $chemin = "integrations/exports/{$rapport->id}.{$rapport->format}";
            Storage::disk('local')->put($chemin, $contenu);

            $rapport->update([
                'status' => 'completed',
                'total_rows' => count($donnees['lignes']),
                'valid_rows' => count($donnees['lignes']),
                'file_path' => $chemin,
                'filename' => $rapport->type.'-'.now()->format('Ymd-His').'.'.$rapport->format,
                'duration_ms' => (int) ((microtime(true) - $debut) * 1000),
            ]);

            IntegrationLog::tracer(
                integration: $rapport->integration,
                direction: 'export',
                operation: "export.{$rapport->type}",
                status: 'completed',
                contenu: $contenu,
                externalReference: $rapport->external_reference,
            );
        } catch (\Throwable $e) {
            $rapport->update(['status' => 'failed', 'errors' => [$e instanceof IoException ? $e->getMessage() : 'Erreur technique pendant l\'export.']]);

            IntegrationLog::tracer(
                integration: $rapport->integration,
                direction: 'export',
                operation: "export.{$rapport->type}",
                status: 'failed',
                errorCode: 'export_failed',
                externalReference: $rapport->external_reference,
            );
        }

        return $rapport->fresh();
    }

    /**
     * Téléchargement contrôlé (§81-82) : disque privé, propriétaire ou
     * super_admin, expiration vérifiée (fichier purgé à la volée).
     */
    public function telecharger(ImportReport $rapport, ?User $utilisateur = null)
    {
        abort_if($rapport->direction !== 'export' || ! $rapport->file_path, 404);

        // §82 : expiration — purge immédiate + journalisation
        if ($rapport->estExpire()) {
            $this->purger($rapport);

            abort(404, 'Export expiré : le fichier a été supprimé (durée de conservation dépassée).');
        }

        abort_unless(Storage::disk('local')->exists($rapport->file_path), 404, 'Fichier d\'export introuvable.');

        return Storage::disk('local')->download(
            $rapport->file_path,
            $rapport->filename ?? 'export.'.$rapport->format,
            ['Content-Type' => IoFactory::serializerPour($rapport->format)->mimeType()],
        );
    }

    /**
     * §82 : purge d'un export expiré (fichier + statut + journal).
     */
    public function purger(ImportReport $rapport): void
    {
        if ($rapport->file_path) {
            Storage::disk('local')->delete($rapport->file_path);
        }

        $rapport->update(['status' => 'expired', 'file_path' => null]);

        IntegrationLog::tracer(
            integration: $rapport->integration,
            direction: 'export',
            operation: 'export.expired',
            status: 'cancelled',
            errorCode: 'expired',
            externalReference: $rapport->external_reference,
        );
    }
}
