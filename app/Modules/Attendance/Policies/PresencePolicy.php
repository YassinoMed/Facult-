<?php

namespace App\Modules\Attendance\Policies;

use App\Models\Etudiant;
use App\Models\User;
use App\Modules\Attendance\Models\Presence;

/**
 * Module Attendance (§12-13/§31) : permission RBAC + isolation faculté,
 * exactement comme les policies du cœur — un module ne contourne
 * jamais la sécurité existante.
 */
class PresencePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('attendance.view');
    }

    public function view(User $user, Presence $presence): bool
    {
        return $user->hasPermission('attendance.view')
            && $user->peutAccederFaculte((int) $presence->etudiant->faculte_id);
    }

    public function create(User $user, Etudiant $etudiant): bool
    {
        return $user->hasPermission('attendance.create')
            && $user->peutAccederFaculte((int) $etudiant->faculte_id);
    }

    public function delete(User $user, Presence $presence): bool
    {
        return $user->hasPermission('attendance.delete')
            && $user->peutAccederFaculte((int) $presence->etudiant->faculte_id);
    }
}
