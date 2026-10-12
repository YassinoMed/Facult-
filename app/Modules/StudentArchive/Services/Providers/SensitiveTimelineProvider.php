<?php

namespace App\Modules\StudentArchive\Services\Providers;

use App\Contracts\StudentArchiveTimelineProvider;
use App\Models\Etudiant;
use App\Modules\StudentArchive\Models\StudentSensitiveRecord;
use App\Modules\StudentArchive\Policies\SensitiveRecordPolicy;
use Illuminate\Support\Collection;

/**
 * Timeline confidentielle (§19) : JAMAIS dans la standard — provider
 * dédié, supports() exige `sensitive.view`, titres neutres uniquement.
 */
class SensitiveTimelineProvider implements StudentArchiveTimelineProvider
{
    public function categorie(): string
    {
        return 'sensible';
    }

    public function supports(Etudiant $etudiant): bool
    {
        return auth()->user()?->can('student_archive.sensitive.view') ?? false;
    }

    public function evenements(Etudiant $etudiant, array $filtres = []): Collection
    {
        $utilisateur = auth()->user();
        $policy = app(SensitiveRecordPolicy::class);

        return StudentSensitiveRecord::where('etudiant_id', $etudiant->id)
            ->latest('id')->limit(50)->get()
            ->filter(fn ($dossier) => $utilisateur !== null && $policy->voir($utilisateur, $dossier))
            ->map(fn ($dossier) => [
                'date' => $dossier->date_debut ?? $dossier->created_at,
                'categorie' => $this->categorie(),
                'titre' => 'Dossier '.$dossier->typeEnum()->libelle().' — '.$dossier->statut,
                'detail' => null,
                'lien' => null,
            ])->values();
    }
}
