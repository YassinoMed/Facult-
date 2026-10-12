<?php

namespace App\Services\Recherche\Providers;

use App\Models\User;
use App\Modules\Evaluation\Models\Epreuve;
use App\Services\Recherche\SearchProvider;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Collection;

class EpreuvesProvider implements SearchProvider
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    public function section(): string
    {
        return 'Épreuves';
    }

    public function eligible(User $utilisateur): bool
    {
        return $utilisateur->can('epreuves.view') && $this->modules->estActif('evaluation');
    }

    public function rechercher(User $utilisateur, string $motif, int $limite): Collection
    {
        $faculteId = $utilisateur->faculteRestreinteId();

        return Epreuve::with(['matiere', 'groupe'])
            ->whereHas('matiere', fn ($m) => $m->where('nom', 'like', $motif)->orWhere('code', 'like', $motif))
            ->when($faculteId !== null, fn ($w) => $w->where(
                fn ($ou) => $ou->whereHas('groupe', fn ($g) => $g->where('faculte_id', $faculteId))
                    ->orWhereHas('matiere.ue.programme', fn ($p) => $p->where('faculte_id', $faculteId))))
            ->limit($limite)->get()
            ->map(fn ($e) => [
                'titre' => ($e->matiere?->code ?? '?').' — '.($e->date?->format('d/m/Y') ?? '?'),
                'detail' => $e->statut,
                'lien' => route('admin.evaluations.epreuves.show', $e),
            ])->values();
    }

    public function routeListe(): ?string
    {
        return 'admin.evaluations.epreuves.index';
    }
}
