<?php

namespace App\Modules\StudentArchive\Policies;

use App\Models\User;
use App\Modules\StudentArchive\Models\StudentSensitiveRecord;

/**
 * Dossiers sensibles (§15-17) : permission par TYPE (médical ≠
 * disciplinaire ≠ sensible) + scope faculté. Archive ≠ Sensitive :
 * `student_archive.view` seul n'ouvre RIEN ici.
 */
class SensitiveRecordPolicy
{
    public function voir(User $utilisateur, StudentSensitiveRecord $dossier): bool
    {
        return $utilisateur->hasPermission($dossier->typeEnum()->permissionLecture())
            && $utilisateur->peutAccederFaculte((int) $dossier->etudiant->faculte_id);
    }

    public function telecharger(User $utilisateur, StudentSensitiveRecord $dossier): bool
    {
        return $dossier->document_source !== null
            && $dossier->document_id !== null
            && $utilisateur->hasPermission($dossier->typeEnum()->permissionTelechargement())
            && $utilisateur->peutAccederFaculte((int) $dossier->etudiant->faculte_id);
    }

    public function gerer(User $utilisateur, StudentSensitiveRecord $dossier): bool
    {
        return $utilisateur->hasPermission('student_archive.sensitive.manage')
            && $utilisateur->peutAccederFaculte((int) $dossier->etudiant->faculte_id);
    }
}
