<?php

namespace App\Modules\AssistantIa\Contracts;

use App\Models\User;

/**
 * Contrat pour le moteur RAG documentaire de UniRegistre AI.
 */
interface RechercheDocumentaireInterface
{
    /**
     * Recherche des extraits documentaires pertinents sous contrôle d'accès.
     *
     * @param  array<string, mixed>  $filtres
     * @return array<int, array{source: string, titre: string, chemin: string, extrait: string, score: float, date_indexation: string}>
     */
    public function rechercher(string $requete, ?User $utilisateur = null, int $limite = 5, array $filtres = []): array;
}
