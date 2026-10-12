<?php

namespace App\Modules\StudentArchive\Services\Providers;

use App\Contracts\StudentArchiveTimelineProvider;
use App\Models\Etudiant;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use Illuminate\Support\Collection;

/**
 * Timeline délibérations (§14, §31) : décisions de jury de l'étudiant.
 * Lecture seule des décisions existantes ; masqué sans `deliberations.view`.
 */
class AcademicTimelineProvider implements StudentArchiveTimelineProvider
{
    public function categorie(): string
    {
        return 'deliberation';
    }

    public function supports(Etudiant $etudiant): bool
    {
        return auth()->user()?->can('deliberations.view') ?? false;
    }

    public function evenements(Etudiant $etudiant, array $filtres = []): Collection
    {
        return DecisionDeliberation::where('etudiant_id', $etudiant->id)
            ->with('deliberation.anneeUniversitaire')
            ->latest('id')->get()
            ->map(fn ($decision) => [
                'date' => $decision->created_at,
                'categorie' => $this->categorie(),
                'titre' => 'Délibération : '.$decision->decision,
                'detail' => trim(($decision->deliberation?->anneeUniversitaire?->libelle ?? '').' · Moyenne '.($decision->moyenne ?? '—'), ' ·'),
                'lien' => $decision->deliberation_id !== null
                    ? route('admin.evaluations.deliberations.show', $decision->deliberation_id) : null,
            ]);
    }
}
