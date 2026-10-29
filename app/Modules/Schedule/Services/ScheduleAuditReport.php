<?php

namespace App\Modules\Schedule\Services;

use Illuminate\Contracts\Support\Arrayable;

/**
 * PHASE 3 (Smart Timetables) — Rapport d'audit indépendant d'un planning.
 * Value Object immuable contenant les violations dures (bloquantes),
 * les pénalités souples (pondérées), les métriques d'occupation et
 * un score global de qualité (0 à 100).
 */
class ScheduleAuditReport implements Arrayable
{
    /**
     * @param  bool  $valide  Indique si le planning est exempt de toute violation dure.
     * @param  int  $scoreQualite  Score de 0 à 100 (100 = optimal).
     * @param  array<int, array<string, mixed>>  $violationsDures  Détails des contraintes dures enfreintes.
     * @param  array<int, array<string, mixed>>  $penalitesSouples  Détails des pénalités souples subies.
     * @param  array<string, mixed>  $metriques  Statistiques d'audit (heures, occupation, trous, etc.).
     * @param  array<int, string>  $recommandations  Conseils d'amélioration lisibles.
     */
    public function __construct(
        private readonly bool $valide,
        private readonly int $scoreQualite,
        private readonly array $violationsDures = [],
        private readonly array $penalitesSouples = [],
        private readonly array $metriques = [],
        private readonly array $recommandations = [],
    ) {}

    public function estValide(): bool
    {
        return $this->valide;
    }

    public function scoreQualite(): int
    {
        return $this->scoreQualite;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function violationsDures(): array
    {
        return $this->violationsDures;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function penalitesSouples(): array
    {
        return $this->penalitesSouples;
    }

    /**
     * @return array<string, mixed>
     */
    public function metriques(): array
    {
        return $this->metriques;
    }

    /**
     * @return array<int, string>
     */
    public function recommandations(): array
    {
        return $this->recommandations;
    }

    public function nombreViolationsDures(): int
    {
        return count($this->violationsDures);
    }

    public function nombrePenalitesSouples(): int
    {
        return count($this->penalitesSouples);
    }

    public function totalPenalites(): float
    {
        return (float) array_sum(array_column($this->penalitesSouples, 'score_penalite'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'est_valide' => $this->valide,
            'score_qualite' => $this->scoreQualite,
            'nb_violations_dures' => $this->nombreViolationsDures(),
            'nb_penalites_souples' => $this->nombrePenalitesSouples(),
            'total_penalites' => $this->totalPenalites(),
            'violations_dures' => $this->violationsDures,
            'penalites_souples' => $this->penalitesSouples,
            'metriques' => $this->metriques,
            'recommandations' => $this->recommandations,
        ];
    }
}
