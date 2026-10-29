<?php

namespace App\Services\Notifications\Contrats;

use App\Models\AppNotification;
use App\Services\Notifications\DTO\DemandeNotification;
use App\Services\Notifications\DTO\ResultatNotification;

/**
 * Contrat d'orchestration pour le Centre de notifications unifié (§4-6, LOT 02).
 */
interface NotificationDispatcherInterface
{
    /**
     * Traite et distribue une notification multicanale unifiée.
     */
    public function distribuer(DemandeNotification $demande): ResultatNotification;

    /**
     * Retente la distribution d'une notification en échec.
     */
    public function retenter(AppNotification $notification): bool;

    /**
     * Consulte l'état de livraison et le suivi d'une notification.
     *
     * @return array{
     *     id: int,
     *     statut_envoi: string,
     *     tentatives: int,
     *     dernier_essai_le: ?string,
     *     erreur: ?string,
     *     read_at: ?string
     * }
     */
    public function statut(AppNotification $notification): array;
}
