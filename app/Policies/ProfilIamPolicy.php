<?php

namespace App\Policies;

use App\Models\Iam\ProfilIam;
use App\Models\User;
use App\Support\Permissions;

/**
 * Politique de sécurité régissant les rôles personnalisés (ProfilIam).
 *
 * Invariants :
 *  - Aucune modification des rôles système n'est autorisée ;
 *  - L'administrateur facultaire ne peut voir, modifier ou affecter que les rôles de sa faculté ;
 *  - Exige 'roles.view' pour la lecture et 'roles.manage' pour toute écriture.
 */
class ProfilIamPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('roles.view');
    }

    public function view(User $user, ProfilIam $profil): bool
    {
        if (! $user->hasPermission('roles.view')) {
            return false;
        }

        $restreinte = $user->faculteRestreinteId();
        if ($restreinte !== null && $profil->faculte_id !== null && (int) $profil->faculte_id !== $restreinte) {
            return false;
        }

        return true;
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('roles.manage');
    }

    public function update(User $user, ProfilIam $profil): bool
    {
        if (! $user->hasPermission('roles.manage')) {
            return false;
        }

        // Les rôles système sont strictement immuables
        if (in_array($profil->code, Permissions::ROLES, true)) {
            return false;
        }

        $restreinte = $user->faculteRestreinteId();
        if ($restreinte !== null && (int) $profil->faculte_id !== $restreinte) {
            return false;
        }

        return true;
    }

    public function delete(User $user, ProfilIam $profil): bool
    {
        return $this->update($user, $profil);
    }

    public function managePermissions(User $user, ProfilIam $profil): bool
    {
        return $this->update($user, $profil);
    }

    public function affecter(User $user, ProfilIam $profil): bool
    {
        if (! $user->hasPermission('roles.manage')) {
            return false;
        }

        if (! $profil->estActif()) {
            return false;
        }

        $restreinte = $user->faculteRestreinteId();
        if ($restreinte !== null && $profil->faculte_id !== null && (int) $profil->faculte_id !== $restreinte) {
            return false;
        }

        return true;
    }
}
