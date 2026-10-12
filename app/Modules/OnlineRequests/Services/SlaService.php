<?php

namespace App\Modules\OnlineRequests\Services;

use App\Models\User;
use App\Modules\Calendar\Services\JoursOuvrables;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;

/**
 * SLA des demandes (§38) : le temps passé en `waiting_student`
 * (attente d'une pièce de l'étudiant) ne compte pas contre
 * l'administration. Escalade quotidienne par commande planifiée.
 */
class SlaService
{
    public function __construct(private NotificationService $notifications) {}

    public function delaiRestantJours(OnlineRequest $demande): ?int
    {
        $echeance = $demande->echeance();

        if ($echeance === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($echeance->startOfDay(), false);
    }

    /**
     * Délai restant en jours OUVRÉS universitaires (§10.16) :
     * week-ends + fériés/vacances du calendrier exclus.
     */
    public function joursOuvresRestants(OnlineRequest $demande): ?int
    {
        $echeance = $demande->echeance();

        if ($echeance === null) {
            return null;
        }

        return app(JoursOuvrables::class)
            ->restants($echeance->format('Y-m-d'));
    }

    public function estEnPause(OnlineRequest $demande): bool
    {
        return $demande->status === 'waiting_student';
    }

    public function estEnRetardImputable(OnlineRequest $demande): bool
    {
        return ! $this->estEnPause($demande) && $demande->enRetard();
    }

    /**
     * @return array{verifiees: int, escaladees: int}
     */
    public function verifierEtEscalader(): array
    {
        $demandes = OnlineRequest::with(['etudiant', 'type', 'responsable'])
            ->whereIn('status', ['submitted', 'received', 'in_review'])
            ->get();

        $verifiees = 0;
        $escaladees = 0;

        foreach ($demandes as $demande) {
            $verifiees++;

            if (! $demande->enRetard()) {
                continue;
            }

            $destinataires = $demande->assigned_to !== null
                ? collect([$demande->assigned_to])
                : User::where('actif', true)
                    ->whereIn('role', ['admin_scolarite', 'agent_scolarite'])
                    ->where(fn ($w) => $w->whereNull('faculte_id')->orWhere('faculte_id', $demande->faculte_id))
                    ->pluck('id');

            if ($destinataires->isEmpty()) {
                continue;
            }

            $this->notifications->notifierPlusieursUsers(
                $destinataires,
                'demande.sla',
                'Demande en retard',
                "{$demande->reference} ({$demande->type->name}) dépasse son délai de traitement.",
                route('admin.demandes.show', $demande),
                ['demande_id' => $demande->id],
                2,
            );

            $escaladees++;

            Log::info('demande.sla.escalade', ['demande_id' => $demande->id, 'reference' => $demande->reference]);
        }

        return ['verifiees' => $verifiees, 'escaladees' => $escaladees];
    }
}
