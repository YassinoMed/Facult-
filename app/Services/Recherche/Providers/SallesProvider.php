<?php

namespace App\Services\Recherche\Providers;

use App\Models\User;
use App\Modules\Schedule\Models\Room;
use App\Services\Recherche\SearchProvider;
use Illuminate\Support\Collection;

class SallesProvider implements SearchProvider
{
    public function section(): string
    {
        return 'Salles';
    }

    public function eligible(User $utilisateur): bool
    {
        return $utilisateur->can('schedules.view');
    }

    public function rechercher(User $utilisateur, string $motif, int $limite): Collection
    {
        return Room::where(fn ($w) => $w
            ->where('code', 'like', $motif)->orWhere('nom', 'like', $motif))
            ->limit($limite)->get()
            ->map(fn ($s) => [
                'titre' => $s->code,
                'detail' => $s->nom ?? '',
                'lien' => route('admin.salles.index'),
            ])->values();
    }

    public function routeListe(): ?string
    {
        return null;
    }
}
