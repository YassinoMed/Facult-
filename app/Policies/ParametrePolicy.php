<?php

namespace App\Policies;

use App\Models\Parametre;
use App\Models\User;

class ParametrePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('settings.view');
    }

    public function update(User $user, Parametre $parametre): bool
    {
        return $user->hasPermission('settings.manage');
    }
}
