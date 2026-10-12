<?php

namespace App\Jobs;

use App\Models\ImportJob;
use App\Models\User;
use App\Services\DataExchange\Contracts\ImportContextuel;
use App\Services\DataExchange\DataImportService;
use App\Services\DataExchange\DefinitionRegistry;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * C2 (LOT 3) — exécution asynchrone d'un import DataExchange volumineux.
 * Staging sur `import_jobs`/`import_job_rows` (Phase 17) — jamais en
 * session. Transition `en_file → en_cours` ATOMIQUE : un dispatch
 * doublon n'applique jamais deux fois. Rapport standard + notification
 * au responsable au terme.
 */
class ExecuteDataImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly int $jobId,
    ) {}

    public function handle(DataImportService $imports, DefinitionRegistry $definitions, NotificationService $notifications): void
    {
        // Garde de concurrence : en_file → en_cours atomique (0 ligne
        // = déjà pris en charge par un autre worker → no-op).
        $pris = ImportJob::whereKey($this->jobId)->where('statut', 'en_file')
            ->update(['statut' => 'en_cours']);

        if ($pris === 0) {
            return;
        }

        $job = ImportJob::with('lignes')->findOrFail($this->jobId);
        $definition = $definitions->import($job->entite);
        $auteurId = (int) $job->auteur_id;
        $options = is_array($job->options) ? $job->options : [];

        // Contexte serveur rejoué (ex. épreuve) : la file n'hérite
        // jamais de contexte client, uniquement du staging posé à
        // la confirmation.
        if ($definition instanceof ImportContextuel) {
            $definition->definirContexte(is_array($options['contexte'] ?? null) ? $options['contexte'] : []);
        }

        try {
            $lignes = $job->lignes()
                ->where('statut', 'valide')
                ->orderBy('numero_ligne')
                ->pluck('donnees_normalisees')
                ->map(fn ($ligne) => is_string($ligne) ? json_decode($ligne, true) : $ligne)
                ->all();

            ['rapport' => $rapport, 'importes' => $importes] = $imports->executer(
                $definition, $job->entite, $lignes,
                (string) $job->nom_fichier, (string) $job->format,
                (string) $job->mode, $auteurId, 'web-file', $job->hash_fichier,
            );

            // Traçabilité PHASE 4 (si revue en preview) : décisions
            // conservées dans le rapport, jamais les valeurs (PII).
            if (isset($options['revue']) && is_array($options['revue'])) {
                $rapport->update(['preview' => array_merge(
                    is_array($rapport->preview) ? $rapport->preview : [],
                    ['revue' => $options['revue']],
                )]);
            }

            $job->update([
                'statut' => 'termine', 'lignes_importees' => $importes, 'confirme_le' => now(),
            ]);

            $this->notifierResultat($notifications, $auteurId, true, "Import « {$job->entite} » terminé : {$importes} ligne(s) importée(s) ({$job->nom_fichier}).");
        } catch (\Throwable $e) {
            $job->update(['statut' => 'erreur']);

            // Jamais de contenu de ligne (PII) dans les logs : identifiants
            // techniques uniquement.
            Log::channel(config('logging.default'))->error('data_import.queued_failed', [
                'import_job_id' => $job->id, 'entite' => $job->entite, 'auteur_id' => $auteurId,
                'message' => mb_substr($e->getMessage(), 0, 200),
            ]);

            $this->notifierResultat($notifications, $auteurId, false, "Import « {$job->entite} » en échec — consultez l'historique Import/Export.");

            throw $e;
        }
    }

    private function notifierResultat(NotificationService $notifications, int $auteurId, bool $succes, string $message): void
    {
        $auteur = User::find($auteurId);

        if ($auteur === null) {
            return;
        }

        $notifications->notifierUser(
            $auteur, 'import_termine',
            $succes ? 'Import terminé' : 'Import en échec',
            $message, route('admin.donnees.historique'),
            [], $succes ? 0 : 2,
        );
    }
}
