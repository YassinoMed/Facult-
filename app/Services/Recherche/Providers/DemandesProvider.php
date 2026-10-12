<?php

namespace App\Services\Recherche\Providers;

use App\Models\User;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Services\Recherche\SearchProvider;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Collection;

class DemandesProvider implements SearchProvider
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    public function section(): string
    {
        return 'Demandes';
    }

    public function eligible(User $utilisateur): bool
    {
        return $utilisateur->can('demandes.view') && $this->modules->estActif('online-requests');
    }

    public function rechercher(User $utilisateur, string $motif, int $limite): Collection
    {
        $faculteId = $utilisateur->faculteRestreinteId();

        return OnlineRequest::with('etudiant')
            ->where(fn ($w) => $w->where('reference', 'like', $motif)->orWhere('objet', 'like', $motif))
            ->when($faculteId !== null, fn ($w) => $w->where('faculte_id', $faculteId))
            ->limit($limite)->get()
            ->map(fn ($d) => [
                'titre' => $d->reference.' — '.$d->objet,
                'detail' => $d->etudiant->nom_complet,
                'lien' => route('admin.demandes.show', $d),
            ])->values();
    }

    public function routeListe(): ?string
    {
        return 'admin.demandes.index';
    }
}
