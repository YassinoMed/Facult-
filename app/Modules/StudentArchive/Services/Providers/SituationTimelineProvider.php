<?php

namespace App\Modules\StudentArchive\Services\Providers;

use App\Contracts\StudentArchiveTimelineProvider;
use App\Models\Etudiant;
use Illuminate\Support\Collection;

/**
 * Timeline situations (§8, §17) : suspensions, transferts, changements de
 * parcours/statut — append-only, motif et auteur conservés.
 */
class SituationTimelineProvider implements StudentArchiveTimelineProvider
{
    public function categorie(): string
    {
        return 'situation';
    }

    public function supports(Etudiant $etudiant): bool
    {
        return true;
    }

    public function evenements(Etudiant $etudiant, array $filtres = []): Collection
    {
        $etudiant->loadMissing('situations');

        return $etudiant->situations->map(fn ($situation) => [
            'date' => $situation->date_effet,
            'categorie' => $this->categorie(),
            'titre' => ucfirst($situation->type).($situation->nouvelle_valeur !== null ? ' : '.$situation->nouvelle_valeur : ''),
            'detail' => $situation->motif,
            'lien' => null,
        ])->values();
    }
}
