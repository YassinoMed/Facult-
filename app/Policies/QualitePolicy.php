<?php

namespace App\Policies;

use App\Models\AnomalieQualite;
use App\Models\User;

class QualitePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('qualite.view');
    }

    public function update(User $user, AnomalieQualite $anomalie): bool
    {
        if (! $user->hasPermission('qualite.manage')) {
            return false;
        }

        $faculteRestreinte = $user->faculteRestreinteId();

        return $faculteRestreinte === null
            || (int) $anomalie->faculte_id === $faculteRestreinte;
    }
}
