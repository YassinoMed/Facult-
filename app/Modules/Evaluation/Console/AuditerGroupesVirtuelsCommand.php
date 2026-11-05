<?php

namespace App\Modules\Evaluation\Console;

use App\Models\IntegrationLog;
use App\Modules\Evaluation\Models\GroupeVirtuelExamen;
use App\Modules\Evaluation\Services\ConsultationExportGroupeVirtuelService;
use App\Modules\Schedule\Models\Group;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Audit planifié, strictement en lecture seule, des groupes virtuels d'examen.
 *
 * Garanties :
 * 1. Aucune génération, validation, publication ou mutation de données.
 * 2. Verrou anti-concurrence (une seule exécution simultanée).
 * 3. Idempotence : une exécution identique ne produit ni doublon de trace
 *    ni nouvelle alerte.
 * 4. Observabilité : état, compteurs et empreinte consultables dans le cache.
 * 5. Aucune PII : seuls des compteurs et une empreinte SHA-256 sont journalisés.
 */
final class AuditerGroupesVirtuelsCommand extends Command
{
    public const CACHE_ETAT = 'examens.groupes_virtuels.dernier_controle';

    public const CACHE_VERROU = 'examens.groupes_virtuels.audit_verrou';

    protected $signature = 'examens:auditer-groupes-virtuels
        {--limit=500 : Nombre maximal de groupes analysés}
        {--json : Sortie machine}';

    protected $description = 'Audite en lecture seule les groupes virtuels d’examen (dérives, blocages, capacité) sans aucune publication.';

    public function handle(ConsultationExportGroupeVirtuelService $service): int
    {
        $limite = max(1, min(5000, (int) $this->option('limit')));
        $verrou = Cache::lock(self::CACHE_VERROU, 300);

        if (! $verrou->get()) {
            $this->warn('Un audit des groupes virtuels est déjà en cours ; exécution ignorée.');

            return self::FAILURE;
        }

        try {
            $resume = $this->executer($service, $limite);
        } finally {
            $verrou->release();
        }

        Cache::put(self::CACHE_ETAT, $resume, now()->addDays(7));

        if ($this->option('json')) {
            $this->line((string) json_encode($resume, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->afficher($resume);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function executer(ConsultationExportGroupeVirtuelService $service, int $limite): array
    {
        $debut = microtime(true);
        $compteurs = [
            'analyses' => 0,
            'conformes' => 0,
            'derives' => 0,
            'bloquants' => 0,
            'sans_salle' => 0,
            'sans_eligible' => 0,
        ];
        $identifiants = [];
        $groupesAnomaux = [];

        GroupeVirtuelExamen::query()
            ->where('statut', GroupeVirtuelExamen::STATUT_PROPOSE)
            ->chunkById(100, function ($groupes) use ($service, $limite, &$compteurs, &$identifiants, &$groupesAnomaux) {
                foreach ($groupes as $groupe) {
                    if ($compteurs['analyses'] >= $limite) {
                        return false;
                    }

                    $sources = Group::query()
                        ->whereIn('id', $groupe->identifiantsSources())
                        ->get();

                    $controle = $service->evaluerCoherenceEtDerive($groupe, $sources);

                    $compteurs['analyses']++;
                    $identifiants[] = (int) $groupe->id;

                    $anomalies = [];

                    if ($controle['en_derive']) {
                        $compteurs['derives']++;
                        $anomalies[] = 'derive';
                    }

                    if (! empty($controle['bloquants'])) {
                        $compteurs['bloquants']++;
                        $anomalies[] = 'bloquant';
                    }

                    if (empty($groupe->epreuve?->salle_id)) {
                        $compteurs['sans_salle']++;
                        $anomalies[] = 'sans_salle';
                    }

                    if ((int) $controle['effectif_eligible'] === 0) {
                        $compteurs['sans_eligible']++;
                        $anomalies[] = 'sans_eligible';
                    }

                    if ($anomalies === []) {
                        $compteurs['conformes']++;
                    } else {
                        $groupesAnomaux[] = [
                            'id' => (int) $groupe->id,
                            'anomalies' => array_values(array_unique($anomalies)),
                        ];
                    }
                }
            });

        $empreinte = hash('sha256', (string) json_encode([
            'ids' => $identifiants,
            'compteurs' => $compteurs,
            'anomaux' => array_column($groupesAnomaux, 'id'),
        ]));

        $precedent = Cache::get(self::CACHE_ETAT);
        $identique = is_array($precedent) && ($precedent['empreinte'] ?? null) === $empreinte;

        if (! $identique && $groupesAnomaux !== []) {
            IntegrationLog::tracer(
                'examens.groupes_virtuels',
                'internal',
                'audit_derive',
                'completed',
                (string) json_encode($compteurs),
                durationMs: (int) round((microtime(true) - $debut) * 1000),
                externalReference: $empreinte,
            );
        }

        return [
            'genere_le' => now()->toDateTimeString(),
            'empreinte' => $empreinte,
            'identique_a_precedent' => $identique,
            'limite' => $limite,
            'plafond_atteint' => $compteurs['analyses'] >= $limite,
            'compteurs' => $compteurs,
            'groupes_anomaux' => array_slice($groupesAnomaux, 0, 100),
            'duree_ms' => (int) round((microtime(true) - $debut) * 1000),
            'aucune_mutation' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $resume
     */
    private function afficher(array $resume): void
    {
        $compteurs = $resume['compteurs'];

        $this->info('Audit des groupes virtuels d’examen — lecture seule');
        $this->line('Groupes analysés : '.$compteurs['analyses']);
        $this->line('Conformes : '.$compteurs['conformes']);
        $this->line('En dérive : '.$compteurs['derives']);
        $this->line('Bloquants : '.$compteurs['bloquants']);
        $this->line('Sans salle : '.$compteurs['sans_salle']);
        $this->line('Sans étudiant éligible : '.$compteurs['sans_eligible']);
        $this->line('Empreinte : '.$resume['empreinte']);
        $this->line('Identique à l’exécution précédente : '.($resume['identique_a_precedent'] ? 'oui' : 'non'));

        if ($resume['plafond_atteint']) {
            $this->warn('Le plafond d’analyse a été atteint : le résultats est partiel.');
        }

        $this->comment('Aucune génération, validation ou publication n’a été effectuée.');
    }
}
