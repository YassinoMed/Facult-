<?php

namespace App\Modules\Schedule\Services;

use App\Models\Matiere;
use App\Modules\Schedule\Models\RemplacementCandidature;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Candidatures de remplacement pédagogique (LOT 09, audit §F9).
 *
 * L'enseignant déclare son volontariat par matière ; l'administration
 * accepte pour un créneau précis. L'acceptation ne duplique AUCUNE
 * mécanique de remplacement : elle invoque le remplacement EDT
 * transactionnel existant (`ScheduleRepairService::remplacerEnseignant`
 * — qualification, disponibilité, chevauchement, historisation
 * `ScheduleChange`) et marque la candidature. Si le remplacement
 * échoue, la candidature reste soumise (transaction commune).
 */
class CandidatureRemplacementService
{
    public function __construct(private readonly ScheduleRepairService $reparations) {}

    /**
     * L'enseignant se déclare disponible pour une matière.
     *
     * @param  array{matiere_id: int, message?: ?string}  $donnees
     */
    public function deposer(Teacher $teacher, array $donnees): RemplacementCandidature
    {
        if (! $teacher->actif) {
            throw ValidationException::withMessages([
                'teacher' => 'Seul un enseignant actif peut se porter candidat.',
            ]);
        }

        $matiere = Matiere::find($donnees['matiere_id'] ?? null);
        if ($matiere === null) {
            throw ValidationException::withMessages([
                'matiere_id' => 'Matière inconnue.',
            ]);
        }

        if (! $teacher->estQualifiePour($matiere->code)) {
            throw ValidationException::withMessages([
                'matiere_id' => "Vous n'êtes pas qualifié pour la matière {$matiere->code}.",
            ]);
        }

        $doublon = RemplacementCandidature::where('teacher_id', $teacher->id)
            ->where('matiere_id', $matiere->id)
            ->where('statut', 'soumise')
            ->exists();

        if ($doublon) {
            throw ValidationException::withMessages([
                'matiere_id' => 'Vous avez déjà une candidature active pour cette matière.',
            ]);
        }

        return RemplacementCandidature::create([
            'teacher_id' => $teacher->id,
            'matiere_id' => $matiere->id,
            'message' => $donnees['message'] ?? null,
            'statut' => 'soumise',
        ]);
    }

    /**
     * L'enseignant retire son volontariat (ses candidatures uniquement).
     */
    public function retirer(RemplacementCandidature $candidature, Teacher $teacher): RemplacementCandidature
    {
        if ((int) $candidature->teacher_id !== (int) $teacher->id) {
            throw ValidationException::withMessages([
                'candidature' => 'Candidature d’un autre enseignant.',
            ]);
        }

        if ($candidature->estSoumise()) {
            $candidature->update(['statut' => 'retiree', 'decided_at' => now()]);
        }

        return $candidature->fresh();
    }

    /**
     * L'administration accepte la candidature POUR un créneau précis :
     * le remplacement EDT transactionnel est réutilisé tel quel — si
     * une garde échoue (qualification perdue, indisponibilité…), la
     * candidature reste soumise et l'EDT inchangé.
     */
    public function accepter(RemplacementCandidature $candidature, ScheduleSlot $slot, ?int $userId): RemplacementCandidature
    {
        if (! $candidature->estSoumise()) {
            throw ValidationException::withMessages([
                'candidature' => 'Seule une candidature soumise peut être acceptée.',
            ]);
        }

        if ((int) $candidature->matiere_id !== (int) $slot->matiere_id) {
            throw ValidationException::withMessages([
                'slot' => 'La candidature porte sur une autre matière que le créneau à remplacer.',
            ]);
        }

        return DB::transaction(function () use ($candidature, $slot, $userId) {
            $this->reparations->remplacerEnseignant(
                $slot,
                (int) $candidature->teacher_id,
                $userId,
                'Candidature de remplacement acceptée'
            );

            $candidature->update([
                'statut' => 'acceptee',
                'decided_by' => $userId,
                'decided_at' => now(),
                'decision_motif' => 'Créneau '.strtolower((string) $slot->day_of_week).' '.substr((string) $slot->start_time, 0, 5).'-'.substr((string) $slot->end_time, 0, 5),
            ]);

            return $candidature->fresh();
        });
    }

    /**
     * L'administration écarte une candidature (motif conservé).
     */
    public function ecarter(RemplacementCandidature $candidature, ?int $userId, ?string $motif = null): RemplacementCandidature
    {
        if ($candidature->estSoumise()) {
            $candidature->update([
                'statut' => 'ecartee',
                'decided_by' => $userId,
                'decided_at' => now(),
                'decision_motif' => $motif,
            ]);
        }

        return $candidature->fresh();
    }

    /**
     * Candidatures actives pour une matière (alimentation des
     * suggestions de remplacement).
     *
     * @return Collection<int, RemplacementCandidature>
     */
    public function activesPourMatiere(int $matiereId)
    {
        return RemplacementCandidature::where('matiere_id', $matiereId)
            ->where('statut', 'soumise')
            ->with('teacher')
            ->get();
    }
}
