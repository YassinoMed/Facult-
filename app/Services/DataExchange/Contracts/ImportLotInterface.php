<?php

namespace App\Services\DataExchange\Contracts;

use App\Services\Io\IoException;

/**
 * Extension optionnelle du pipeline DataExchange pour les définitions
 * capables de persister un paquet de lignes en une seule opération
 * métier (import de notes, par exemple).
 *
 * Le contrat reste séparé de `ImportDefinitionInterface` : les
 * définitions qui ne l'implémentent pas conservent la boucle ligne à
 * ligne historique.
 */
interface ImportLotInterface
{
    /**
     * Persiste un paquet déjà validé.
     *
     * @param  array<int, array<string, mixed>>  $lignes
     * @return int nombre de lignes traitées
     *
     * @throws IoException
     */
    public function persisterLot(array $lignes, string $mode, ?int $auteurId): int;
}
