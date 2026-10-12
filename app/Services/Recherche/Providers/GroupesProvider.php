<?php

namespace App\Services\Recherche\Providers;

use App\Models\User;
use App\Modules\Schedule\Models\Group;
use App\Services\Recherche\SearchProvider;
use Illuminate\Support\Collection;

class GroupesProvider implements SearchProvider
{
    public function section(): string
    {
        return 'Groupes';
    }

    public function eligible(User $utilisateur): bool
    {
        return $utilisateur->can('schedules.view');
    }

    public function rechercher(User $utilisateur, string $motif, int $limite): Collection
    {
        $faculteId = $utilisateur->faculteRestreinteId();

        return Group::with('faculte')
            ->where(fn ($w) => $w->where('code', 'like', $motif)->orWhere('nom', 'like', $motif))
            ->when($faculteId !== null, fn ($w) => $w->where('faculte_id', $faculteId))
            ->limit($limite)->get()
            ->map(fn ($g) => [
                'titre' => $g->code.' — '.$g->nom,
                'detail' => $g->faculte?->code ?? '',
                'lien' => route('admin.groupes.show', $g),
            ])->values();
    }

    public function routeListe(): ?string
    {
        return 'admin.groupes.index';
    }
}
