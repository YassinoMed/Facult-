<?php

namespace App\Services\Recherche;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Contrat des providers de recherche (Phase 7 §7.2) : un provider =
 * une entité, requête scopée (permission + périmètre faculté),
 * limite bornée. Forme de sortie stable {titre, detail, lien}.
 */
interface SearchProvider
{
    public function section(): string;

    public function eligible(User $utilisateur): bool;

    /**
     * @return Collection<int, array{titre: string, detail: string, lien: ?string}>
     */
    public function rechercher(User $utilisateur, string $motif, int $limite): Collection;

    public function routeListe(): ?string;
}
