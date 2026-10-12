<?php

namespace App\Modules\StudentArchive\Services\Providers;

use App\Contracts\StudentArchiveTimelineProvider;
use App\Models\Etudiant;
use Illuminate\Support\Collection;

/**
 * Timeline documents académiques (§13) : téléversements du dossier.
 * Métadonnées seules (jamais de contenu binaire en timeline).
 */
class AcademicDocumentTimelineProvider implements StudentArchiveTimelineProvider
{
    public function categorie(): string
    {
        return 'document';
    }

    public function supports(Etudiant $etudiant): bool
    {
        return true;
    }

    public function evenements(Etudiant $etudiant, array $filtres = []): Collection
    {
        $etudiant->loadMissing('documentsAcademiques.anneeUniversitaire');

        return $etudiant->documentsAcademiques
            ->when(isset($filtres['annee_universitaire_id']),
                fn ($c) => $c->where('annee_universitaire_id', (int) $filtres['annee_universitaire_id']))
            ->map(fn ($document) => [
                'date' => $document->created_at,
                'categorie' => $this->categorie(),
                'titre' => 'Document : '.($document->type_document ?? 'document'),
                'detail' => $document->nom_original,
                'lien' => null,
            ])->values();
    }
}
