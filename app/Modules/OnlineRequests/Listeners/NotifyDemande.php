<?php

namespace App\Modules\OnlineRequests\Listeners;

use App\Modules\OnlineRequests\Events\OnlineRequestApproved;
use App\Modules\OnlineRequests\Events\OnlineRequestCompleted;
use App\Modules\OnlineRequests\Events\OnlineRequestRejected;
use App\Modules\OnlineRequests\Events\OnlineRequestWaitingForStudent;
use App\Services\NotificationService;
use Throwable;

/**
 * Notifications demandes (§5) : l'étudiant suit chaque décision sur
 * SON dossier, avec lien direct. Jamais de contenu sensible.
 * Fail-soft (LOOP 16) : la décision est déjà commise au dispatch, une
 * panne de notification ne la remet jamais en cause. Idempotent via
 * les clés de déduplication (un event rejoué ne duplique pas).
 */
class NotifyDemande
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    public function approbation(OnlineRequestApproved $event): void
    {
        $demande = $event->demande;

        $this->envoyer(
            $demande->etudiant,
            'demande.approved',
            'Demande approuvée',
            "Votre demande {$demande->reference} ({$demande->type->name}) a été approuvée.",
            $demande,
            ['reference' => $demande->reference],
            1,
            "demande.approved:{$demande->id}",
        );
    }

    public function rejet(OnlineRequestRejected $event): void
    {
        $demande = $event->demande;

        $this->envoyer(
            $demande->etudiant,
            'demande.rejected',
            'Demande rejetée',
            "Votre demande {$demande->reference} a été rejetée : {$demande->rejection_reason}",
            $demande,
            ['reference' => $demande->reference],
            2,
            "demande.rejected:{$demande->id}",
        );
    }

    public function cloture(OnlineRequestCompleted $event): void
    {
        $demande = $event->demande;

        $this->envoyer(
            $demande->etudiant,
            'demande.completed',
            'Demande terminée',
            "Votre demande {$demande->reference} est terminée.",
            $demande,
            ['reference' => $demande->reference],
            0,
            "demande.completed:{$demande->id}",
        );
    }

    public function pieceAttendee(OnlineRequestWaitingForStudent $event): void
    {
        $demande = $event->demande;

        $this->envoyer(
            $demande->etudiant,
            'demande.waiting',
            'Pièce justificative attendue',
            "Votre demande {$demande->reference} nécessite une pièce : {$event->commentaire}",
            $demande,
            ['reference' => $demande->reference],
            1,
            "demande.waiting:{$demande->id}",
        );
    }

    private function envoyer(
        ?object $etudiant,
        string $type,
        string $titre,
        string $message,
        object $demande,
        array $data,
        int $priorite,
        string $cleDedup,
    ): void {
        try {
            if ($etudiant === null) {
                return;
            }

            $this->notifications->notifierEtudiant(
                $etudiant,
                $type,
                $titre,
                $message,
                route('etudiant.demandes.show', $demande),
                $data,
                $priorite,
                $cleDedup,
            );
        } catch (Throwable) {
            // Fail-soft : la décision et son audit restent la source de vérité.
        }
    }
}
