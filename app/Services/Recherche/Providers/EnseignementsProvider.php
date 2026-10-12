<?php

namespace App\Services\Recherche\Providers;

use App\Models\User;
use App\Modules\Enseignement\Models\Enseignement;
use App\Services\Recherche\SearchProvider;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Collection;

class EnseignementsProvider implements SearchProvider
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    public function section(): string
    {
        return 'Enseignements';
    }

    public function eligible(User $utilisateur): bool
    {
        return $utilisateur->can('enseignements.view') && $this->modules->estActif('enseignement');
    }

    public function rechercher(User $utilisateur, string $motif, int $limite): Collection
    {
        $faculteId = $utilisateur->faculteRestreinteId();

        return Enseignement::with('matiere')
            ->whereHas('matiere', fn ($m) => $m->where('nom', 'like', $motif)->orWhere('code', 'like', $motif))
            ->when($faculteId !== null, fn ($w) => $w->whereHas('matiere.ue.programme', fn ($p) => $p->where('faculte_id', $faculteId)))
            ->limit($limite)->get()
            ->map(fn ($e) => [
                'titre' => ($e->matiere?->code ?? '?').' — '.($e->type?->code ?? '?'),
                'detail' => $e->statut,
                'lien' => route('admin.enseignements.show', $e),
            ])->values();
    }

    public function routeListe(): ?string
    {
        return 'admin.enseignements.index';
    }
}
