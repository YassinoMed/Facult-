<?php

namespace App\Services\Recherche\Providers;

use App\Models\User;
use App\Modules\Schedule\Models\Teacher;
use App\Services\Recherche\SearchProvider;
use Illuminate\Support\Collection;

class EnseignantsProvider implements SearchProvider
{
    public function section(): string
    {
        return 'Enseignants';
    }

    public function eligible(User $utilisateur): bool
    {
        return $utilisateur->can('schedules.view');
    }

    public function rechercher(User $utilisateur, string $motif, int $limite): Collection
    {
        // Phase B : même périmètre que la page (transversaux + sa faculté).
        $restreinte = $utilisateur->faculteRestreinteId();

        return Teacher::where(fn ($w) => $w
            ->where('nom', 'like', $motif)->orWhere('prenom', 'like', $motif)->orWhere('email', 'like', $motif)
            ->orWhere('matricule', 'like', $motif))
            ->when($restreinte !== null, fn ($q) => $q->where(
                fn ($w) => $w->whereNull('departement_id')
                    ->orWhereHas('departement', fn ($d) => $d->where('faculte_id', $restreinte))
            ))
            ->limit($limite)->get()
            ->map(fn ($t) => [
                'titre' => $t->nomComplet(),
                'detail' => ($t->matricule ? $t->matricule.' · ' : '').($t->email ?? '—'),
                'lien' => route('admin.enseignants.show', $t),
            ])->values();
    }

    public function routeListe(): ?string
    {
        return 'admin.enseignants.index';
    }
}
