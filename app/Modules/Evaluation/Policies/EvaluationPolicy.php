<?php

namespace App\Modules\Evaluation\Policies;

use App\Models\User;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\NoteEpreuve;
use App\Modules\Evaluation\Models\SessionExamen;

/**
 * Politiques Évaluation (§32-34) : permission RBAC + périmètre faculté
 * sur chaque objet (anti-IDOR/BOLA). Le périmètre est résolu via
 * programme→faculté, groupe→faculté, ou ECUE→UE→programme→faculté.
 */
class EvaluationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('evaluations.view');
    }

    public function view(User $user, SessionExamen|Examen|Epreuve|NoteEpreuve|Deliberation $objet): bool
    {
        // Aligné sur les middlewares de route : les épreuves acceptent
        // `epreuves.view`, les délibérations `deliberations.view`
        // (jury/scolarité sans `evaluations.view` sinon 403 sur show/PV).
        $hasPerm = match (true) {
            $objet instanceof Epreuve => $user->hasPermission('epreuves.view') || $user->hasPermission('evaluations.view'),
            $objet instanceof Deliberation => $user->hasPermission('deliberations.view') || $user->hasPermission('evaluations.view'),
            default => $user->hasPermission('evaluations.view'),
        };

        return $hasPerm && $this->dansPerimetre($user, $objet);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('evaluations.create') || $user->hasPermission('epreuves.create');
    }

    public function update(User $user, SessionExamen|Examen|Epreuve|Deliberation $objet): bool
    {
        $hasPerm = $objet instanceof Epreuve
            ? ($user->hasPermission('epreuves.update') || $user->hasPermission('evaluations.update'))
            : $user->hasPermission('evaluations.update');

        return $hasPerm && $this->dansPerimetre($user, $objet);
    }

    public function delete(User $user, SessionExamen|Examen|Epreuve $objet): bool
    {
        return $user->hasPermission('evaluations.delete') && $this->dansPerimetre($user, $objet);
    }

    public function saisirNote(User $user, Epreuve $epreuve): bool
    {
        return $user->hasPermission('notes_eval.enter') && $this->dansPerimetre($user, $epreuve);
    }

    public function genererCompostage(User $user, Epreuve $epreuve): bool
    {
        return $user->hasPermission('notes.compostage.generate') && $this->dansPerimetre($user, $epreuve);
    }

    public function resoudreCompostage(User $user, Epreuve $epreuve): bool
    {
        return $user->hasPermission('notes.compostage.resolve') && $this->dansPerimetre($user, $epreuve);
    }

    public function validerNote(User $user, NoteEpreuve $note): bool
    {
        return $user->hasPermission('notes_eval.validate') && $this->dansPerimetre($user, $note);
    }

    public function validerNotesEpreuve(User $user, Epreuve $epreuve): bool
    {
        return $user->hasPermission('notes_eval.validate') && $this->dansPerimetre($user, $epreuve);
    }

    public function corriger(User $user, NoteEpreuve $note): bool
    {
        return $user->hasPermission('notes_eval.correct') && $this->dansPerimetre($user, $note);
    }

    public function deliberer(User $user, Deliberation $deliberation): bool
    {
        return $user->hasPermission('deliberations.manage') && $this->dansPerimetre($user, $deliberation);
    }

    private function dansPerimetre(User $user, SessionExamen|Examen|Epreuve|NoteEpreuve|Deliberation $objet): bool
    {
        if ($user->faculteRestreinteId() === null) {
            return true;
        }

        // Relations chargées par type (Epreuve et SessionExamen n'ont
        // pas de `programme` direct — loadMissing global levait 500).
        $objet->loadMissing(match (true) {
            $objet instanceof NoteEpreuve => ['epreuve.matiere.ue.programme.faculte', 'epreuve.groupe.faculte', 'etudiant.faculte'],
            $objet instanceof Epreuve => ['groupe.faculte', 'matiere.ue.programme.faculte'],
            $objet instanceof Examen => ['programme.faculte', 'groupe.faculte'],
            $objet instanceof Deliberation => ['programme.faculte', 'groupe.faculte'],
            default => [],
        });

        if ($objet instanceof NoteEpreuve) {
            $epreuveFaculteId = $objet->epreuve?->groupe?->faculte_id
                ?? $objet->epreuve?->matiere?->ue?->programme?->faculte_id;
            $etudiantFaculteId = $objet->etudiant?->faculte_id;

            if ($epreuveFaculteId !== null && ! $user->peutAccederFaculte((int) $epreuveFaculteId)) {
                return false;
            }

            if ($etudiantFaculteId !== null && ! $user->peutAccederFaculte((int) $etudiantFaculteId)) {
                return false;
            }

            return true;
        }

        $faculteId = match (true) {
            $objet instanceof SessionExamen => null,
            $objet instanceof Examen => $objet->programme?->faculte_id ?? $objet->groupe?->faculte_id,
            $objet instanceof Epreuve => $objet->groupe?->faculte_id ?? $objet->matiere?->ue?->programme?->faculte_id,
            $objet instanceof Deliberation => $objet->programme?->faculte_id ?? $objet->groupe?->faculte_id,
            default => null,
        };

        return $faculteId === null || $user->peutAccederFaculte((int) $faculteId);
    }
}
