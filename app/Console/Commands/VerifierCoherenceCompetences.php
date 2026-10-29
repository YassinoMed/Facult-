<?php

namespace App\Console\Commands;

use App\Modules\Referentiel\Services\CouvertureCompetencesService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 20 — contrôle planifié de cohérence des associations
 * compétence ↔ programme/UE/ECUE.
 *
 * STRICTEMENT en lecture seule : aucune correction automatique, aucune
 * écriture métier. Les anomalies (compétence non approuvée, cible
 * introuvable, type inconnu) sont listées pour une correction humaine.
 * Code de sortie toujours SUCCESS : une anomalie est un constat, pas un
 * échec d'exécution (le job planifié ne doit pas être marqué en erreur).
 */
class VerifierCoherenceCompetences extends Command
{
    protected $signature = 'competences:coherence {--json : Sortie JSON pour supervision}';

    protected $description = 'Contrôle en lecture seule de la cohérence des associations de compétences.';

    public function handle(CouvertureCompetencesService $service): int
    {
        if (! config('modules.referentiel.active', true)) {
            $this->warn('Module référentiel désactivé : contrôle de cohérence ignoré.');

            return self::SUCCESS;
        }

        // Fail-soft : la planification peut précéder l'application de la
        // migration (aucune migration destructive n'est lancée par le lot).
        if (! Schema::hasTable('competence_objectifs')) {
            $this->warn('Table competence_objectifs absente (migration non appliquée) : contrôle ignoré.');

            return self::SUCCESS;
        }

        $anomalies = $service->verifierCoherence();
        $total = count($anomalies);

        if ($this->option('json')) {
            $this->line(json_encode([
                'total' => $total,
                'anomalies' => $anomalies,
            ], JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info("Associations compétence ↔ cible : {$total} anomalie(s).");

        if ($total > 0) {
            $this->table(
                ['Objectif', 'Anomalie'],
                array_map(fn (array $anomalie) => [
                    $anomalie['competence_objectif_id'],
                    $anomalie['anomalie'],
                ], $anomalies),
            );

            // Aucune donnée personnelle : identifiants techniques et codes.
            Log::warning('Compétences : contrôle de cohérence — anomalies détectées.', ['total' => $total]);
        }

        return self::SUCCESS;
    }
}
