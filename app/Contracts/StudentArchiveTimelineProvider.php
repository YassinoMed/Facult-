<?php

namespace App\Contracts;

use App\Models\Etudiant;
use Illuminate\Support\Collection;

/**
 * Fournisseur d'événements pour la timeline archive (§20) : chaque domaine
 * expose ses événements SANS dépendre du module StudentArchive (le module
 * découvre les providers, jamais l'inverse).
 *
 * Événement : ['date' => Carbon|string, 'categorie' => string,
 *              'titre' => string, 'detail' => ?string, 'lien' => ?string].
 */
interface StudentArchiveTimelineProvider
{
    public function categorie(): string;

    public function supports(Etudiant $etudiant): bool;

    /**
     * @param  array<string, mixed>  $filtres  (annee_universitaire_id, categorie, limite, offset)
     * @return Collection<int, array{date: mixed, categorie: string, titre: string, detail: ?string, lien: ?string}>
     */
    public function evenements(Etudiant $etudiant, array $filtres = []): Collection;
}
