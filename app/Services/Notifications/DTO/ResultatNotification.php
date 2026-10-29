<?php

namespace App\Services\Notifications\DTO;

use App\DataMesh\Outbox\OutboxMessage;
use App\Models\AppNotification;

/**
 * Résultat de l'acheminement d'une notification multicanale unifiée.
 */
class ResultatNotification
{
    /**
     * @param  bool  $succes  True si au moins un canal a été activé ou si la notification a été traitée
     * @param  string  $statut  'envoye', 'ignore_preference', 'deduplique', 'echec'
     * @param  AppNotification|null  $notification  L'entité AppNotification persistée le cas échéant
     * @param  OutboxMessage|null  $outboxMessage  Le message outbox enregistré le cas échéant
     * @param  string|null  $erreur  Message d'erreur nettoyé si échec
     * @param  array<string, mixed>  $details  Métadonnées sur les canaux activés
     */
    public function __construct(
        public readonly bool $succes,
        public readonly string $statut,
        public readonly ?AppNotification $notification = null,
        public readonly ?OutboxMessage $outboxMessage = null,
        public readonly ?string $erreur = null,
        public readonly array $details = [],
    ) {}

    public function aEteIgnoreeParPreference(): bool
    {
        return $this->statut === 'ignore_preference';
    }

    public function aEteDedupliquee(): bool
    {
        return $this->statut === 'deduplique';
    }
}
