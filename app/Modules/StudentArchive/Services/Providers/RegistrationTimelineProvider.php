<?php

namespace App\Modules\StudentArchive\Services\Providers;

use App\Contracts\StudentArchiveTimelineProvider;
use App\Models\Etudiant;
use Illuminate\Support\Collection;

/**
 * Timeline inscriptions (§7) : années, formations, niveaux, statuts.
 * Source unique : inscriptionsAcademiques (aucune table archive).
 */
class RegistrationTimelineProvider implements StudentArchiveTimelineProvider
{
    public function categorie(): string
    {
        return 'inscription';
    }

    public function supports(Etudiant $etudiant): bool
    {
        return true;
    }

    public function evenements(Etudiant $etudiant, array $filtres = []): Collection
    {
        $etudiant->loadMissing('inscriptionsAcademiques.anneeUniversitaire');

        return $etudiant->inscriptionsAcademiques
            ->when(isset($filtres['annee_universitaire_id']),
                fn ($c) => $c->where('annee_universitaire_id', (int) $filtres['annee_universitaire_id']))
            ->map(fn ($inscription) => [
                'date' => $inscription->created_at ?? $inscription->anneeUniversitaire?->date_debut,
                'categorie' => $this->categorie(),
                'titre' => 'Inscription '.($inscription->anneeUniversitaire?->libelle ?? ''),
                'detail' => trim(($inscription->niveau ?? '').' · '.($inscription->statut ?? ''), ' ·'),
                'lien' => null,
            ])->values();
    }
}
