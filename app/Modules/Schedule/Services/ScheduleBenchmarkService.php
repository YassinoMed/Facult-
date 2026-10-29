<?php

namespace App\Modules\Schedule\Services;

use App\Modules\Schedule\Models\Schedule;
use Illuminate\Support\Facades\DB;

/**
 * Service de benchmark, mesure de charge et optimisation de performance (Phase 11).
 * Mesure avec précision les temps de chargement, génération, audit, écriture, export
 * et audite l'absence de requêtes N+1.
 */
class ScheduleBenchmarkService
{
    public function __construct(
        protected ScheduleGeneratorService $generator,
        protected ScheduleConstraintChecker $constraintChecker,
        protected ScheduleExchangeService $exchangeService
    ) {}

    /**
     * Mesure la performance de la génération automatique d'un emploi du temps.
     *
     * @param  array<int, SeanceDemande>  $seancesDemandes
     * @return array{
     *     temps_resolution_ms: float,
     *     nb_creneaux_generes: int,
     *     nb_requetes_sql: int,
     *     memoire_pic_mo: float
     * }
     */
    public function mesurerPerformanceGeneration(Schedule $schedule, array $seancesDemandes = []): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $memAvant = memory_get_usage(true);
        $t0 = hrtime(true);

        $options = ['dry_run' => true];
        if (! empty($seancesDemandes)) {
            $options['seances_demandes'] = $seancesDemandes;
        }

        $generation = $this->generator->simuler($schedule, $options);

        $t1 = hrtime(true);
        $memApres = memory_get_peak_usage(true);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $dureeMs = round(($t1 - $t0) / 1e6, 2);
        $memoireMo = round(($memApres - $memAvant) / (1024 * 1024), 2);

        return [
            'temps_resolution_ms' => $generation->duree_ms > 0 ? (float) $generation->duree_ms : $dureeMs,
            'nb_creneaux_generes' => (int) $generation->nombre_seances_placees,
            'nb_requetes_sql' => count($queries),
            'memoire_pic_mo' => max(0, $memoireMo),
        ];
    }

    /**
     * Mesure la performance de l'audit indépendant par ScheduleConstraintChecker.
     *
     * @return array{
     *     temps_audit_ms: float,
     *     nb_conflits_durs: int,
     *     score_qualite: int,
     *     nb_requetes_sql: int
     * }
     */
    public function mesurerPerformanceAudit(Schedule $schedule): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $t0 = hrtime(true);

        $audit = $this->constraintChecker->auditerPlanning($schedule);

        $t1 = hrtime(true);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $dureeMs = round(($t1 - $t0) / 1e6, 2);

        return [
            'temps_audit_ms' => $dureeMs,
            'nb_conflits_durs' => count($audit->violationsDures()),
            'score_qualite' => $audit->scoreQualite(),
            'nb_requetes_sql' => count($queries),
        ];
    }

    /**
     * Mesure la performance d'export (iCal RFC 5545, CSV, JSON).
     *
     * @return array{
     *     temps_export_ics_ms: float,
     *     temps_export_csv_ms: float,
     *     temps_export_json_ms: float,
     *     taille_ics_octets: int,
     *     nb_requetes_sql: int
     * }
     */
    public function mesurerPerformanceExport(Schedule $schedule): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        // 1. Export ICS
        $t0 = hrtime(true);
        $ics = $this->exchangeService->exporterIcs($schedule);
        $t1 = hrtime(true);

        // 2. Export CSV
        $t2 = hrtime(true);
        $csv = $this->exchangeService->exporterCsv($schedule);
        $t3 = hrtime(true);

        // 3. Export JSON
        $t4 = hrtime(true);
        $json = $this->exchangeService->exporterJson($schedule);
        $t5 = hrtime(true);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        return [
            'temps_export_ics_ms' => round(($t1 - $t0) / 1e6, 2),
            'temps_export_csv_ms' => round(($t3 - $t2) / 1e6, 2),
            'temps_export_json_ms' => round(($t5 - $t4) / 1e6, 2),
            'taille_ics_octets' => strlen($ics),
            'nb_requetes_sql' => count($queries),
        ];
    }

    /**
     * Exécute un benchmark complet multi-itérations et produit des statistiques robustes (p50, p95, max).
     *
     * @param  array<int, SeanceDemande>  $seancesDemandes
     * @return array{
     *     iterations: int,
     *     generation: array{p50_ms: float, p95_ms: float, max_ms: float},
     *     audit: array{p50_ms: float, p95_ms: float, max_ms: float},
     *     export_ics: array{p50_ms: float, p95_ms: float, max_ms: float},
     *     evaluation_n_plus_un: string
     * }
     */
    public function executerBenchmarkComplet(Schedule $schedule, array $seancesDemandes, int $iterations = 3): array
    {
        $tempsGen = [];
        $tempsAudit = [];
        $tempsExport = [];
        $maxRequetesAudit = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $mGen = $this->mesurerPerformanceGeneration($schedule, $seancesDemandes);
            $tempsGen[] = $mGen['temps_resolution_ms'];

            $mAudit = $this->mesurerPerformanceAudit($schedule);
            $tempsAudit[] = $mAudit['temps_audit_ms'];
            $maxRequetesAudit = max($maxRequetesAudit, $mAudit['nb_requetes_sql']);

            $mExport = $this->mesurerPerformanceExport($schedule);
            $tempsExport[] = $mExport['temps_export_ics_ms'];
        }

        // Évaluation N+1 : l'audit exhaustif multi-contraintes reste maîtrisé sous le plafond de 120 requêtes
        $evalNPlusUn = $maxRequetesAudit <= 120 ? 'optimal_sans_n_plus_un' : 'potentiel_n_plus_un_a_surveiller';

        return [
            'iterations' => $iterations,
            'generation' => $this->calculerStatistiques($tempsGen),
            'audit' => $this->calculerStatistiques($tempsAudit),
            'export_ics' => $this->calculerStatistiques($tempsExport),
            'evaluation_n_plus_un' => $evalNPlusUn,
        ];
    }

    /**
     * @param  array<int, float>  $valeurs
     * @return array{p50_ms: float, p95_ms: float, max_ms: float}
     */
    protected function calculerStatistiques(array $valeurs): array
    {
        if (empty($valeurs)) {
            return ['p50_ms' => 0.0, 'p95_ms' => 0.0, 'max_ms' => 0.0];
        }

        sort($valeurs);
        $count = count($valeurs);

        $idx50 = (int) floor($count * 0.50);
        $idx95 = min($count - 1, (int) floor($count * 0.95));

        return [
            'p50_ms' => round($valeurs[$idx50], 2),
            'p95_ms' => round($valeurs[$idx95], 2),
            'max_ms' => round(max($valeurs), 2),
        ];
    }
}
