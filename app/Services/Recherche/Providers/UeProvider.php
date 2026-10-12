<?php

namespace App\Services\Recherche\Providers;

use App\Models\User;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Services\Recherche\SearchProvider;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Collection;

class UeProvider implements SearchProvider
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    public function section(): string
    {
        return 'UE';
    }

    public function eligible(User $utilisateur): bool
    {
        return $utilisateur->can('referentiel.view') && $this->modules->estActif('referentiel');
    }

    public function rechercher(User $utilisateur, string $motif, int $limite): Collection
    {
        $faculteId = $utilisateur->faculteRestreinteId();

        return UniteEnseignement::with('programme')
            ->where(fn ($w) => $w->where('nom', 'like', $motif)->orWhere('code', 'like', $motif))
            ->when($faculteId !== null, fn ($w) => $w->whereHas('programme', fn ($p) => $p->where('faculte_id', $faculteId)))
            ->limit($limite)->get()
            ->map(fn ($u) => [
                'titre' => $u->code.' — '.$u->nom,
                'detail' => $u->programme?->code ?? '',
                'lien' => null,
            ])->values();
    }

    public function routeListe(): ?string
    {
        return null;
    }
}
