<?php

namespace App\Policies;

use App\Models\GroupeDestinataires;
use App\Models\User;

class GroupeDestinatairesPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('campagnes.view');
    }

    public function view(User $user, GroupeDestinataires $groupe): bool
    {
        return $user->hasPermission('campagnes.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('campagnes.manage');
    }

    public function update(User $user, GroupeDestinataires $groupe): bool
    {
        return $user->hasPermission('campagnes.manage') && ! $groupe->est_archive;
    }

    public function delete(User $user, GroupeDestinataires $groupe): bool
    {
        return $user->hasPermission('campagnes.manage');
    }
}
