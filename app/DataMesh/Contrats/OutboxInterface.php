<?php

namespace App\DataMesh\Contrats;

use App\DataMesh\Outbox\OutboxMessage;

/**
 * Contrat du service Transactional Outbox (§7 du prompt).
 * Découple la persistance des événements au sein de la transaction
 * de leur publication vers les listeners ou modules abonnés.
 */
interface OutboxInterface
{
    /**
     * Enregistre un message dans l'outbox (au sein de la transaction active).
     *
     * @param  array<string, mixed>  $chargeUtile
     */
    public function enregistrer(
        string $typeEvenement,
        string $typeAgregat,
        int $agregatId,
        array $chargeUtile,
        ?string $cleDeduplication = null,
    ): OutboxMessage;

    /**
     * Publie les messages en attente (après commit de la transaction).
     *
     * @return int Nombre de messages effectivement publiés
     */
    public function publierEnAttente(int $limite = 50): int;

    /**
     * Réessaie les messages en échec n'ayant pas atteint le seuil maximal.
     *
     * @return int Nombre de messages réexpédiés
     */
    public function retenterEchecs(int $maxTentatives = 3): int;
}
