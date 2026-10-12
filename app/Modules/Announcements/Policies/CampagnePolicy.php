<?php

namespace App\Modules\Announcements\Policies;

use App\Models\User;
use App\Modules\Announcements\Models\Campagne;

class CampagnePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('campagnes.view');
    }

    public function view(User $user, Campagne $campagne): bool
    {
        if (! $user->hasPermission('campagnes.view')) {
            return false;
        }

        return $campagne->faculte_id === null || $user->peutAccederFaculte((int) $campagne->faculte_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('campagnes.manage');
    }

    public function update(User $user, Campagne $campagne): bool
    {
        return $user->hasPermission('campagnes.manage')
            && ($campagne->faculte_id === null || $user->peutAccederFaculte((int) $campagne->faculte_id));
    }
}
