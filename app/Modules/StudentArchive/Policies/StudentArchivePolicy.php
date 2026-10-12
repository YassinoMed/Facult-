<?php

namespace App\Modules\StudentArchive\Policies;

use App\Models\Etudiant;
use App\Models\User;

/**
 * Archive étudiant (§29, §31) : permission + scope faculté (anti-IDOR/BOLA).
 * Consultée explicitement (l'étudiant garde EtudiantPolicy pour le reste).
 */
class StudentArchivePolicy
{
    public function voir(User $utilisateur, Etudiant $etudiant): bool
    {
        return $utilisateur->hasPermission('student_archive.view')
            && $utilisateur->peutAccederFaculte((int) $etudiant->faculte_id);
    }

    public function voirAudit(User $utilisateur, Etudiant $etudiant): bool
    {
        return $utilisateur->hasPermission('student_archive.audit.view')
            && $utilisateur->peutAccederFaculte((int) $etudiant->faculte_id);
    }
}
