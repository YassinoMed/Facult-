<?php

namespace App\Services;

use App\Models\AffectationProposal;
use App\Models\HistoriqueAcademique;
use Illuminate\Database\Eloquent\Builder;

/**
 * LOOP D — Métriques d'automatisation dérivées (§14), sans PII.
 *
 * Agrégats calculés sur données observables (journaux d'audit,
 * propositions) : aucun compteur nouveau, aucune table, taux `null`
 * quand le total est nul (jamais de 0 % trompeur).
 */
class AutomationMetricsService
{
    /**
     * Taux d'affectation automatique réussie dérivé des audits
     * (`affectation.auto_assignee` vs `affectation.auto_non_assignee`).
     *
     * @return array{assignees: int, non_assignees: int, total: int, taux: ?float}
     */
    public function tauxAffectationAuto(?int $faculteId = null, ?string $depuis = null): array
    {
        $compter = fn (string $action): int => HistoriqueAcademique::where('action', $action)
            ->when($faculteId !== null, fn (Builder $q) => $q->whereHas(
                'etudiant', fn (Builder $e) => $e->where('faculte_id', $faculteId)
            ))
            ->when($depuis !== null, fn (Builder $q) => $q->where('created_at', '>=', $depuis))
            ->count();

        $assignees = $compter('affectation.auto_assignee');
        $nonAssignees = $compter('affectation.auto_non_assignee');
        $total = $assignees + $nonAssignees;

        return [
            'assignees' => $assignees,
            'non_assignees' => $nonAssignees,
            'total' => $total,
            'taux' => $total > 0 ? round($assignees / $total * 100, 1) : null,
        ];
    }

    /**
     * Propositions d'affectation par statut. Taux d'intervention =
     * part non acceptée directement (convention de présentation).
     *
     * @return array{par_statut: array<string, int>, total: int, taux_intervention: ?float}
     */
    public function propositions(?int $faculteId = null): array
    {
        $base = AffectationProposal::query()
            ->when($faculteId !== null, fn (Builder $q) => $q->whereHas(
                'student', fn (Builder $e) => $e->where('faculte_id', $faculteId)
            ));

        $parStatut = (clone $base)
            ->selectRaw('statut, COUNT(*) as total')
            ->groupBy('statut')
            ->pluck('total', 'statut')
            ->map(fn ($total) => (int) $total)
            ->all();

        $total = array_sum($parStatut);
        $acceptees = $parStatut[AffectationProposal::STATUT_ACCEPTEE] ?? 0;

        return [
            'par_statut' => $parStatut,
            'total' => $total,
            'taux_intervention' => $total > 0 ? round(($total - $acceptees) / $total * 100, 1) : null,
        ];
    }
}
