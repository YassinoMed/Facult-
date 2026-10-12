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
        return $user->hasPermission('qualite.manage');
    }
}
