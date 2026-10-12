<?php

namespace App\Modules\Calendar\Policies;

use App\Models\User;
use App\Modules\Calendar\Models\EvenementCalendrier;

class CalendrierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('calendriers.view');
    }

    public function view(User $user, EvenementCalendrier $evenement): bool
    {
        if (! $user->hasPermission('calendriers.view')) {
            return false;
        }

        return $evenement->faculte_id === null || $user->peutAccederFaculte((int) $evenement->faculte_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('calendriers.manage');
    }

    public function update(User $user, EvenementCalendrier $evenement): bool
    {
        return $user->hasPermission('calendriers.manage')
            && ($evenement->faculte_id === null || $user->peutAccederFaculte((int) $evenement->faculte_id));
    }

    public function delete(User $user, EvenementCalendrier $evenement): bool
    {
        // Périmètre comme `update` (audit sécurité 04/10/2026) : la
        // suppression doit être aussi stricte que la modification.
        return $user->hasPermission('calendriers.manage')
            && ($evenement->faculte_id === null || $user->peutAccederFaculte((int) $evenement->faculte_id));
    }
}
