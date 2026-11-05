<?php

namespace App\Console\Commands;

use App\Models\IntegrationLog;
use App\Models\User;
use App\Modules\Evaluation\Models\GroupeVirtuelExamen;
use App\Modules\Evaluation\Services\ConsultationExportGroupeVirtuelService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class VerifierDerivesCapacitesCommand extends Command
{
    /**
     * Signature de la commande d'inspection planifiée des dérives et capacités.
     *
     * @var string
     */
    protected $signature = 'evaluation:verifier-derives-capacites
                            {--annee= : Identifiant de l\'année universitaire}
                            {--session= : Identifiant de la session d\'examen}
                            {--faculte= : Identifiant de la faculté}
                            {--auto-resync : Resynchroniser automatiquement les propositions en dérive non validées}
                            {--json : Renvoyer le résultat structuré en JSON}';

    /**
     * Description de la commande.
     *
     * @var string
     */
    protected $description = 'Inspecte périodiquement les dérives de population et les capacités de salles des examens finaux.';

    public function handle(ConsultationExportGroupeVirtuelService $service): int
    {
        $debut = microtime(true);
        $requestId = (string) Str::uuid();

        // 1. Verrouillage anti-concurrence (idempotence stricte)
        $cleVerrou = 'lock_evaluation_verifier_derives_capacites';
        $verrou = Cache::lock($cleVerrou, 300);

        if (! $verrou->get()) {
            $this->warn('Une exécution de vérification des dérives et capacités est déjà en cours. Opération ignorée.');

            IntegrationLog::create([
                'integration' => 'evaluation_automated_check',
                'direction' => 'internal',
                'operation' => 'verification_derives_capacites',
                'status' => 'cancelled',
                'external_reference' => 'lock_busy',
                'request_id' => $requestId,
                'http_status' => 409,
                'duration_ms' => 0,
                'error_code' => Str::limit('CONCURRENT_EXECUTION_BLOCKED', 50, ''),
                'payload_hash' => hash('sha256', 'concurrent'),
            ]);

            return self::SUCCESS;
        }

        try {
            $utilisateurSysteme = User::where('role', 'super_admin')->first()
                ?? User::first();

            $filtres = [
                'annee_id' => $this->option('annee') ? (int) $this->option('annee') : null,
                'session_id' => $this->option('session') ? (int) $this->option('session') : null,
                'faculte_id' => $this->option('faculte') ? (int) $this->option('faculte') : null,
            ];

            $this->info('Lancement de l\'audit automatique des dérives de population et des capacités D-04…');

            $diagnostic = $service->listerAnomaliesCentralisees($filtres, $utilisateurSysteme);
            $anomalies = $diagnostic['anomalies'];
            $compteurs = $diagnostic['compteurs'];

            $resynchronises = 0;
            if ($this->option('auto-resync') && $compteurs['derives'] > 0) {
                $groupesEnDerive = GroupeVirtuelExamen::query()
                    ->whereIn('id', array_unique(array_column($anomalies, 'groupe_id')))
                    ->where('statut', GroupeVirtuelExamen::STATUT_PROPOSE)
                    ->get();

                foreach ($groupesEnDerive as $gv) {
                    try {
                        $service->resynchroniserGroupeVirtuel($gv, (int) $utilisateurSysteme->id);
                        $resynchronises++;
                    } catch (\Throwable $e) {
                        $this->warn("Échec resynchronisation auto #{$gv->id}: {$e->getMessage()}");
                    }
                }
            }

            $dureeMs = (int) round((microtime(true) - $debut) * 1000);

            // Audit dans IntegrationLog (0 PII, direction = internal, error_code <= 50)
            IntegrationLog::create([
                'integration' => 'evaluation_automated_check',
                'direction' => 'internal',
                'operation' => 'verification_derives_capacites',
                'status' => $compteurs['bloquants'] > 0 ? 'failed' : 'completed',
                'external_reference' => 'anomalies:'.$compteurs['total'],
                'request_id' => $requestId,
                'http_status' => 200,
                'duration_ms' => $dureeMs,
                'error_code' => $compteurs['bloquants'] > 0 ? Str::limit('BLOQUANTS_'.$compteurs['bloquants'], 50, '') : null,
                'payload_hash' => hash('sha256', json_encode($compteurs)),
            ]);

            $resume = [
                'total_anomalies' => $compteurs['total'],
                'bloquants' => $compteurs['bloquants'],
                'avertissements' => $compteurs['avertissements'],
                'derives' => $compteurs['derives'],
                'capacites' => $compteurs['capacites'],
                'resynchronises' => $resynchronises,
                'duree_ms' => $dureeMs,
            ];

            if ($this->option('json')) {
                $this->line(json_encode($resume, JSON_PRETTY_PRINT));
            } else {
                $this->table(
                    ['Métrique', 'Valeur'],
                    [
                        ['Total anomalies détectées', $compteurs['total']],
                        ['Bloquants majeurs', $compteurs['bloquants']],
                        ['Avertissements', $compteurs['avertissements']],
                        ['Dérives de population', $compteurs['derives']],
                        ['Anomalies de capacité / salle D-04', $compteurs['capacites']],
                        ['Propositions auto-resynchronisées', $resynchronises],
                        ['Durée d\'exécution (ms)', $dureeMs],
                    ]
                );
                $this->info("Audit terminé avec succès en {$dureeMs} ms.");
            }

            return self::SUCCESS;
        } finally {
            $verrou->release();
        }
    }
}
