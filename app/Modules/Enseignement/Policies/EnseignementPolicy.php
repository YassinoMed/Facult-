<?php

namespace App\Modules\Enseignement\Policies;

use App\Models\User;
use App\Modules\Enseignement\Models\Enseignement;

/**
 * Politique enseignements (§46-47) : permission + périmètre faculté
 * (via ECUE → UE → programme).
 */
class EnseignementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('enseignements.view');
    }

    public function view(User $user, Enseignement $enseignement): bool
    {
        return $user->hasPermission('enseignements.view')
            && $this->dansPerimetre($user, $enseignement);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('enseignements.manage');
    }

    public function update(User $user, Enseignement $enseignement): bool
    {
        return $user->hasPermission('enseignements.manage')
            && $this->dansPerimetre($user, $enseignement);
    }

    public function delete(User $user, Enseignement $enseignement): bool
    {
        return $user->hasPermission('enseignements.manage')
            && $this->dansPerimetre($user, $enseignement);
    }

    public function valider(User $user, Enseignement $enseignement): bool
    {
        return $user->hasPermission('enseignements.valider')
            && $this->dansPerimetre($user, $enseignement);
    }

    private function dansPerimetre(User $user, Enseignement $enseignement): bool
    {
        $enseignement->loadMissing('matiere.ue.programme');

        $faculteId = $enseignement->matiere?->ue?->programme?->faculte_id;

        return $faculteId === null || $user->peutAccederFaculte((int) $faculteId);
    }
}
