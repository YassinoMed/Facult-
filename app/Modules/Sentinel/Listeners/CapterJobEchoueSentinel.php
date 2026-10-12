<?php

namespace App\Modules\Sentinel\Listeners;

use App\Modules\Sentinel\Models\SentinelIncident;
use Illuminate\Database\QueryException;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;

/**
 * Platform Sentinel — capture immédiate des jobs asynchrones en échec.
 * S'exécute lors de l'événement Laravel `JobFailed`.
 * Déduplique par nom de job et file d'attente (sans PII ni fuite de trace).
 */
class CapterJobEchoueSentinel
{
    public function handle(JobFailed $event): void
    {
        if (! (bool) config('modules.sentinel.active', false)) {
            return;
        }

        try {
            $nomJob = class_basename($event->job->resolveName());
            $queue = (string) $event->job->getQueue();
            $connexion = (string) $event->connectionName;

            // Diagnostic nettoyé : suppression des chemins locaux et secrets éventuels
            $messageBrut = $event->exception->getMessage();
            $messageNettoye = (string) preg_replace('/(\/[a-zA-Z0-9_\-.\/]+)/', '[chemin]', $messageBrut);
            $messageNettoye = (string) preg_replace('/(password|secret|token|cle)[=:][^\s,]+/i', '$1=***', $messageNettoye);
            $messageNettoye = mb_substr(trim($messageNettoye), 0, 250);

            $cle = sha1("INFRA_JOB_ECHOUE|{$nomJob}|{$queue}");

            $incident = SentinelIncident::where('cle_unique', $cle)->first();

            if ($incident !== null) {
                $incident->update([
                    'statut' => $incident->statut === SentinelIncident::STATUT_RESOLU
                        || $incident->statut === SentinelIncident::STATUT_IGNORE
                        ? SentinelIncident::STATUT_OUVERT
                        : $incident->statut,
                    'derniere_detection_le' => now(),
                    'compteur' => $incident->compteur + 1,
                    'diagnostic' => "Échec répété (connexion {$connexion}, file {$queue}) : {$messageNettoye}",
                ]);

                return;
            }

            try {
                SentinelIncident::create([
                    'cle_unique' => $cle,
                    'code' => 'INFRA_JOB_ECHOUE',
                    'categorie' => 'infrastructure',
                    'gravite' => 'erreur',
                    'module' => 'queues',
                    'titre' => "Job en échec : {$nomJob} (file {$queue})",
                    'diagnostic' => "Échec du job sur {$connexion}/{$queue} : {$messageNettoye}",
                    'recommandation' => "Vérifier la cause de l'exception dans les logs applicatifs, corriger l'état puis relancer via `php artisan queue:retry`.",
                    'statut' => SentinelIncident::STATUT_OUVERT,
                    'entite_type' => 'job',
                    'entite_libelle' => $nomJob,
                    'detecte_le' => now(),
                    'derniere_detection_le' => now(),
                    'compteur' => 1,
                ]);
            } catch (QueryException) {
                $existant = SentinelIncident::where('cle_unique', $cle)->first();
                if ($existant !== null) {
                    $existant->update([
                        'statut' => $existant->statut === SentinelIncident::STATUT_RESOLU
                            || $existant->statut === SentinelIncident::STATUT_IGNORE
                            ? SentinelIncident::STATUT_OUVERT
                            : $existant->statut,
                        'derniere_detection_le' => now(),
                        'compteur' => $existant->compteur + 1,
                        'diagnostic' => "Échec répété (connexion {$connexion}, file {$queue}) : {$messageNettoye}",
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Fail-soft : l'échec de la capture Sentinel ne doit jamais interrompre le traitement
            Log::warning('sentinel.capture_job_echec', [
                'message' => mb_substr($e->getMessage(), 0, 150),
            ]);
        }
    }
}
