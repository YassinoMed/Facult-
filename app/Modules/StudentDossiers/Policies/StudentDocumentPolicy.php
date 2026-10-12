<?php

namespace App\Modules\StudentDossiers\Policies;

use App\Models\Etudiant;
use App\Models\User;
use App\Modules\StudentDossiers\Models\StudentDocument;

/**
 * Documents étudiants (§8) : permission granulaire par action +
 * isolation faculté via dossier → étudiant. Le remplacement exige
 * `replace`, la validation `validate`, le rejet `reject`.
 */
class StudentDocumentPolicy
{
    private function dansPerimetre(User $user, Etudiant $etudiant): bool
    {
        return $user->peutAccederFaculte((int) $etudiant->faculte_id);
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('student_documents.view');
    }

    public function view(User $user, StudentDocument $document): bool
    {
        return $user->hasPermission('student_documents.view')
            && $this->dansPerimetre($user, $document->dossier->etudiant);
    }

    public function upload(User $user, Etudiant $etudiant): bool
    {
        return $user->hasPermission('student_documents.upload')
            && $this->dansPerimetre($user, $etudiant);
    }

    public function download(User $user, StudentDocument $document): bool
    {
        return $user->hasPermission('student_documents.download')
            && $this->dansPerimetre($user, $document->dossier->etudiant);
    }

    public function validate(User $user, StudentDocument $document): bool
    {
        return $user->hasPermission('student_documents.validate')
            && $this->dansPerimetre($user, $document->dossier->etudiant);
    }

    public function reject(User $user, StudentDocument $document): bool
    {
        return $user->hasPermission('student_documents.reject')
            && $this->dansPerimetre($user, $document->dossier->etudiant);
    }

    public function replace(User $user, StudentDocument $document): bool
    {
        return $user->hasPermission('student_documents.replace')
            && $this->dansPerimetre($user, $document->dossier->etudiant);
    }

    public function delete(User $user, StudentDocument $document): bool
    {
        return $user->hasPermission('student_documents.delete')
            && $this->dansPerimetre($user, $document->dossier->etudiant);
    }

    public function audit(User $user, StudentDocument $document): bool
    {
        return $user->hasPermission('student_documents.audit')
            && $this->dansPerimetre($user, $document->dossier->etudiant);
    }
}
