<?php

namespace App\Services\Recherche\Providers;

use App\Models\User;
use App\Modules\Schedule\Models\Schedule;
use App\Services\Recherche\SearchProvider;
use Illuminate\Support\Collection;

class EmploisDuTempsProvider implements SearchProvider
{
    public function section(): string
    {
        return 'Emplois du temps';
    }

    public function eligible(User $utilisateur): bool
    {
        return $utilisateur->can('schedules.view');
    }

    public function rechercher(User $utilisateur, string $motif, int $limite): Collection
    {
        $faculteId = $utilisateur->faculteRestreinteId();

        return Schedule::with('groupe')
            ->where(fn ($w) => $w->where('nom', 'like', $motif))
            ->when($faculteId !== null, fn ($w) => $w->whereHas('groupe', fn ($g) => $g->where('faculte_id', $faculteId)))
            ->limit($limite)->get()
            ->map(fn ($s) => [
                'titre' => $s->nom,
                'detail' => $s->groupe->code,
                'lien' => route('admin.emplois-du-temps.show', $s),
            ])->values();
    }

    public function routeListe(): ?string
    {
        return 'admin.emplois-du-temps.index';
    }
}
