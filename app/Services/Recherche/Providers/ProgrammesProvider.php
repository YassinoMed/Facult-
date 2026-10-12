<?php

namespace App\Services\Recherche\Providers;

use App\Models\User;
use App\Modules\Referentiel\Models\Programme;
use App\Services\Recherche\SearchProvider;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Collection;

class ProgrammesProvider implements SearchProvider
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    public function section(): string
    {
        return 'Programmes';
    }

    public function eligible(User $utilisateur): bool
    {
        return $utilisateur->can('referentiel.view') && $this->modules->estActif('referentiel');
    }

    public function rechercher(User $utilisateur, string $motif, int $limite): Collection
    {
        $faculteId = $utilisateur->faculteRestreinteId();

        return Programme::where(fn ($w) => $w
            ->where('nom', 'like', $motif)->orWhere('code', 'like', $motif))
            ->when($faculteId !== null, fn ($w) => $w->where('faculte_id', $faculteId))
            ->limit($limite)->get()
            ->map(fn ($p) => [
                'titre' => $p->code.' — '.$p->nom,
                'detail' => $p->faculte?->code ?? '',
                'lien' => route('admin.referentiel.tableau-bord'),
            ])->values();
    }

    public function routeListe(): ?string
    {
        return 'admin.referentiel.tableau-bord';
    }
}
