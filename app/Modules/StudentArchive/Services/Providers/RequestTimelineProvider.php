<?php

namespace App\Modules\StudentArchive\Services\Providers;

use App\Contracts\StudentArchiveTimelineProvider;
use App\Models\Etudiant;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Collection;

/**
 * Timeline demandes (§19, §31) : Transitions visibles, module actif
 * uniquement, permission `demandes.view` du contexte (supports).
 */
class RequestTimelineProvider implements StudentArchiveTimelineProvider
{
    public function categorie(): string
    {
        return 'demande';
    }

    public function supports(Etudiant $etudiant): bool
    {
        return app(ModuleRegistry::class)->estActif('online-requests')
            && (auth()->user()?->can('demandes.view') ?? false);
    }

    public function evenements(Etudiant $etudiant, array $filtres = []): Collection
    {
        return OnlineRequest::where('etudiant_id', $etudiant->id)
            ->latest('id')->limit(50)->get()
            ->map(fn ($demande) => [
                'date' => $demande->submitted_at ?? $demande->created_at,
                'categorie' => $this->categorie(),
                'titre' => 'Demande '.$demande->reference.' : '.$demande->statut,
                'detail' => $demande->objet,
                'lien' => route('admin.demandes.show', $demande),
            ]);
    }
}
