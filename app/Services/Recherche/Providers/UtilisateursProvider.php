<?php

namespace App\Services\Recherche\Providers;

use App\Models\User;
use App\Services\Recherche\SearchProvider;
use Illuminate\Support\Collection;

class UtilisateursProvider implements SearchProvider
{
    public function section(): string
    {
        return 'Utilisateurs';
    }

    public function eligible(User $utilisateur): bool
    {
        return $utilisateur->estSuperAdmin();
    }

    public function rechercher(User $utilisateur, string $motif, int $limite): Collection
    {
        return User::where(fn ($w) => $w
            ->where('name', 'like', $motif)->orWhere('email', 'like', $motif))
            ->limit($limite)->get()
            ->map(fn ($u) => [
                'titre' => $u->name,
                'detail' => $u->email.' · '.$u->role_libelle,
                'lien' => route('admin.administrateurs.index'),
            ])->values();
    }

    public function routeListe(): ?string
    {
        return 'admin.administrateurs.index';
    }
}
