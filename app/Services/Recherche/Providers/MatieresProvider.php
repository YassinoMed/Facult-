<?php

namespace App\Services\Recherche\Providers;

use App\Models\Matiere;
use App\Models\User;
use App\Services\Recherche\SearchProvider;
use Illuminate\Support\Collection;

class MatieresProvider implements SearchProvider
{
    public function section(): string
    {
        return 'Matières';
    }

    public function eligible(User $utilisateur): bool
    {
        return $utilisateur->can('schedules.view');
    }

    public function rechercher(User $utilisateur, string $motif, int $limite): Collection
    {
        // INV-6 (audit 08/10/2026) : périmètre faculté via le scope
        // canonique — un restreint ne voit que ses ECUE + les matières
        // transversales (sans UE), pas le catalogue des autres facultés.
        return Matiere::pourFaculte($utilisateur->faculteRestreinteId())
            ->where(fn ($w) => $w
                ->where('nom', 'like', $motif)->orWhere('code', 'like', $motif))
            ->limit($limite)->get()
            ->map(fn ($m) => [
                'titre' => $m->code.' — '.$m->nom,
                'detail' => $m->niveau,
                // Pas de fiche matière côté back-office : pas de lien.
                'lien' => null,
            ])->values();
    }

    public function routeListe(): ?string
    {
        return null;
    }
}
