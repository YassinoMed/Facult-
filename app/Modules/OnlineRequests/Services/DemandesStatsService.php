<?php

namespace App\Modules\OnlineRequests\Services;

use App\Modules\OnlineRequests\Models\OnlineRequest;

/**
 * Analytique des demandes (Phase 9 §78) : volumes, retard, délais —
 * requêtes agrégées (jamais N+1), scopées faculté par l'appelant.
 */
class DemandesStatsService
{
    /**
     * @return array{total: int, en_retard: int, delai_moyen_jours: ?float, par_service: array<string, int>, par_statut: array<string, int>}
     */
    public function statistiques(?int $faculteId): array
    {
        $base = OnlineRequest::query()
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId));

        $parStatut = (clone $base)->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')->pluck('total', 'status')->map(fn ($v) => (int) $v)->all();

        $parService = (clone $base)->join('request_types', 'request_types.id', '=', 'online_requests.request_type_id')
            ->groupBy('request_types.code')->selectRaw('request_types.code as code, COUNT(*) as total')
            ->pluck('total', 'code')->map(fn ($v) => (int) $v)->all();

        $enRetard = (clone $base)
            ->whereIn('status', ['submitted', 'received', 'in_review'])
            ->whereNotNull('submitted_at')
            ->get()->filter(fn (OnlineRequest $d) => $d->enRetard())->count();

        // Délai moyen de traitement (portable SQLite/MySQL : moyenne
        // calculée en PHP sur deux colonnes, requêtes agrégées sinon).
        $durees = (clone $base)
            ->whereNotNull('submitted_at')
            ->whereNotNull('completed_at')
            ->select(['submitted_at', 'completed_at'])->get()
            ->map(fn (OnlineRequest $d) => $d->submitted_at->diffInSeconds($d->completed_at))
            ->filter(fn ($s) => $s >= 0)->values();

        return [
            'total' => array_sum($parStatut),
            'en_retard' => $enRetard,
            'delai_moyen_jours' => $durees->isNotEmpty() ? round($durees->avg() / 86400, 1) : null,
            'par_service' => $parService,
            'par_statut' => $parStatut,
        ];
    }
}
