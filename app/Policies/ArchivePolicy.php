<?php

namespace App\Policies;

use App\Models\DossierAcademique;
use App\Models\User;

/**
 * Politique d'accès aux dossiers académiques (archives).
 * Toute action exige à la fois la permission RBAC ET l'appartenance de
 * la ressource au périmètre de faculté de l'utilisateur (§6 isolation).
 */
class ArchivePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('archives.view');
    }

    public function view(User $user, DossierAcademique $dossier): bool
    {
        return $user->hasPermission('archives.view')
            && $user->peutAccederFaculte((int) $dossier->etudiant->faculte_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('archives.create');
    }

    public function update(User $user, DossierAcademique $dossier): bool
    {
        return $user->hasPermission('archives.update')
            && $user->peutAccederFaculte((int) $dossier->etudiant->faculte_id);
    }

    public function delete(User $user, DossierAcademique $dossier): bool
    {
        return $user->hasPermission('archives.delete')
            && $user->peutAccederFaculte((int) $dossier->etudiant->faculte_id);
    }
}
