<?php

namespace App\Policies;

use App\Models\Etudiant;
use App\Models\User;

/**
 * Politique du registre des étudiants (back-office).
 *
 * Double contrôle pour chaque action :
 * 1. permission RBAC requise ;
 * 2. isolation par faculté : un admin_faculte n'agit QUE sur les
 *    étudiants de SA faculté — même en manipulant un ID dans l'URL
 *    (anti-IDOR/BOLA §6). Les rôles globaux (super_admin, scolarité
 *    centrale) ne sont pas restreints.
 */
class EtudiantPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // le périmètre (global/faculté) est scopé dans la requête
    }

    public function view(User $user, Etudiant $etudiant): bool
    {
        return $user->peutAccederFaculte((int) $etudiant->faculte_id);
    }

    public function update(User $user, Etudiant $etudiant): bool
    {
        return $user->hasPermission('archives.update')
            && $user->peutAccederFaculte((int) $etudiant->faculte_id);
    }

    public function delete(User $user, Etudiant $etudiant): bool
    {
        return $user->hasPermission('archives.delete')
            && $user->peutAccederFaculte((int) $etudiant->faculte_id);
    }
}
