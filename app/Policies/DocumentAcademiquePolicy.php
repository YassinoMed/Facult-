<?php

namespace App\Policies;

use App\Models\DocumentAcademique;
use App\Models\User;

/**
 * Politique des documents académiques sensibles.
 * Le téléchargement exige explicitement documents.download ; toute action
 * reste limitée au périmètre de faculté de l'utilisateur (§6 isolation).
 */
class DocumentAcademiquePolicy
{
    public function view(User $user, DocumentAcademique $document): bool
    {
        return $user->hasPermission('documents.view')
            && $user->peutAccederFaculte((int) $document->etudiant->faculte_id);
    }

    public function upload(User $user): bool
    {
        return $user->hasPermission('documents.upload');
    }

    public function download(User $user, DocumentAcademique $document): bool
    {
        return $user->hasPermission('documents.download')
            && $user->peutAccederFaculte((int) $document->etudiant->faculte_id);
    }

    public function delete(User $user, DocumentAcademique $document): bool
    {
        return $user->hasPermission('documents.delete')
            && $user->peutAccederFaculte((int) $document->etudiant->faculte_id);
    }
}
