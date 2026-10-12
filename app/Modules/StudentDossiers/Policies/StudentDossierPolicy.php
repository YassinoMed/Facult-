<?php

namespace App\Modules\StudentDossiers\Policies;

use App\Models\User;
use App\Modules\StudentDossiers\Models\StudentDossier;

/**
 * Dossiers étudiants (§8) : permission RBAC + isolation faculté via
 * l'étudiant rattaché — même schéma que EtudiantPolicy du cœur.
 */
class StudentDossierPolicy
{
    public function viewAny(User $user): bool
    {
        // Le périmètre (global/faculté) est scopé dans la requête.
        return $user->hasPermission('student_dossiers.view');
    }

    public function view(User $user, StudentDossier $dossier): bool
    {
        return $user->hasPermission('student_dossiers.view')
            && $user->peutAccederFaculte((int) $dossier->etudiant->faculte_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('student_dossiers.create');
    }

    public function update(User $user, StudentDossier $dossier): bool
    {
        return $user->hasPermission('student_dossiers.update')
            && $user->peutAccederFaculte((int) $dossier->etudiant->faculte_id);
    }

    public function delete(User $user, StudentDossier $dossier): bool
    {
        return $user->hasPermission('student_dossiers.delete')
            && $user->peutAccederFaculte((int) $dossier->etudiant->faculte_id);
    }
}
