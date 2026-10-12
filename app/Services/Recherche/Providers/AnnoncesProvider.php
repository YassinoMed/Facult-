<?php

namespace App\Services\Recherche\Providers;

use App\Models\User;
use App\Modules\Announcements\Models\Annonce;
use App\Services\Recherche\SearchProvider;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Collection;

class AnnoncesProvider implements SearchProvider
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    public function section(): string
    {
        return 'Annonces';
    }

    public function eligible(User $utilisateur): bool
    {
        return $utilisateur->can('annonces.view') && $this->modules->estActif('announcements');
    }

    public function rechercher(User $utilisateur, string $motif, int $limite): Collection
    {
        $faculteId = $utilisateur->faculteRestreinteId();

        return Annonce::where('statut', 'published')
            ->where(fn ($w) => $w->where('titre', 'like', $motif)->orWhere('contenu', 'like', $motif))
            ->when($faculteId !== null, fn ($w) => $w->where(fn ($x) => $x->whereNull('faculte_id')->orWhere('faculte_id', $faculteId)))
            ->limit($limite)->get()
            ->map(fn ($a) => [
                'titre' => $a->titre,
                'detail' => $a->type,
                'lien' => route('admin.annonces.index'),
            ])->values();
    }

    public function routeListe(): ?string
    {
        return 'admin.annonces.index';
    }
}
