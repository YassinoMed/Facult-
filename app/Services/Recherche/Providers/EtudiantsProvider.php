<?php

namespace App\Services\Recherche\Providers;

use App\Models\Etudiant;
use App\Models\User;
use App\Services\Recherche\SearchProvider;
use Illuminate\Support\Collection;

/**
 * Provider Étudiants : nom, prénom, CIN, email, n° inscription.
 * PII — permission archives.view + scope faculté (anti-fuite).
 */
class EtudiantsProvider implements SearchProvider
{
    public function section(): string
    {
        return 'Étudiants';
    }

    public function eligible(User $utilisateur): bool
    {
        return $utilisateur->can('archives.view');
    }

    public function rechercher(User $utilisateur, string $motif, int $limite): Collection
    {
        $faculteId = $utilisateur->faculteRestreinteId();

        return Etudiant::with('faculte')
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->where(fn ($w) => $w
                ->where('nom', 'like', $motif)->orWhere('prenom', 'like', $motif)
                ->orWhere('cin', 'like', $motif)->orWhere('email', 'like', $motif)
                ->orWhere('num_inscription', 'like', $motif))
            ->limit($limite)->get()
            ->map(fn ($e) => [
                'titre' => $e->nom_complet,
                'detail' => $e->cin.' · '.$e->faculte?->code,
                'lien' => route('admin.etudiants.show', $e),
            ])->values();
    }

    public function routeListe(): ?string
    {
        return 'admin.etudiants.index';
    }
}
