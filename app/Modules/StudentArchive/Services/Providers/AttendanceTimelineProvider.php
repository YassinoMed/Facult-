<?php

namespace App\Modules\StudentArchive\Services\Providers;

use App\Contracts\StudentArchiveTimelineProvider;
use App\Models\Etudiant;
use App\Modules\Attendance\Models\Presence;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Collection;

/**
 * Timeline absences (§15, §31) : relevés non-présents uniquement
 * (présences ordinaires = bruit). Module actif + `attendance.view`.
 */
class AttendanceTimelineProvider implements StudentArchiveTimelineProvider
{
    public function categorie(): string
    {
        return 'absence';
    }

    public function supports(Etudiant $etudiant): bool
    {
        return app(ModuleRegistry::class)->estActif('attendance')
            && (auth()->user()?->can('attendance.view') ?? false);
    }

    public function evenements(Etudiant $etudiant, array $filtres = []): Collection
    {
        return Presence::where('etudiant_id', $etudiant->id)
            ->whereIn('statut', ['absent', 'retard', 'excuse'])
            ->latest('date')->latest('id')->limit(50)->get()
            ->map(fn ($presence) => [
                'date' => $presence->date,
                'categorie' => $this->categorie(),
                'titre' => ucfirst($presence->statut).' le '.$presence->date->format('d/m/Y'),
                'detail' => trim(($presence->seance ?? '').' '.($presence->motif ?? '')),
                'lien' => null,
            ]);
    }
}
