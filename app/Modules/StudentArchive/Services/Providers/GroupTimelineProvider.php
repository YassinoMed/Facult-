<?php

namespace App\Modules\StudentArchive\Services\Providers;

use App\Contracts\StudentArchiveTimelineProvider;
use App\Models\Etudiant;
use Illuminate\Support\Collection;

/**
 * Timeline affectations (§8) : groupes + années, statut courant webs.
 * Source unique : affectationsGroupe historisées (aucune table archive).
 */
class GroupTimelineProvider implements StudentArchiveTimelineProvider
{
    public function categorie(): string
    {
        return 'groupe';
    }

    public function supports(Etudiant $etudiant): bool
    {
        return true;
    }

    public function evenements(Etudiant $etudiant, array $filtres = []): Collection
    {
        $etudiant->loadMissing('affectations.groupe', 'affectations.anneeUniversitaire');

        return $etudiant->affectations
            ->when(isset($filtres['annee_universitaire_id']),
                fn ($c) => $c->where('annee_universitaire_id', (int) $filtres['annee_universitaire_id']))
            ->map(fn ($affectation) => [
                'date' => $affectation->date_debut,
                'categorie' => $this->categorie(),
                'titre' => 'Affectation au groupe '.($affectation->groupe?->code ?? '—'),
                'detail' => trim(($affectation->anneeUniversitaire?->libelle ?? '').' · '.$affectation->statut, ' ·'),
                'lien' => $affectation->groupe !== null ? route('admin.groupes.show', $affectation->groupe) : null,
            ])->values();
    }
}
