<?php

namespace App\Services\Recherche;

use App\Models\User;

/**
 * Phase 18 §18.1 : abstraction du moteur de recherche (choix SGBD
 * natif documenté dans la migration : zéro dépendance externe).
 */
interface SearchEngine
{
    /**
     * @return array<int, array{type_entite: string, entite_id: int, titre: string, sous_titre: ?string, lien: ?string}>
     */
    public function chercher(User $utilisateur, string $requete, int $limite = 20): array;

    public function estDisponible(): bool;
}
