<?php

namespace App\Modules\Announcements\Policies;

use App\Models\User;
use App\Modules\Announcements\Models\Annonce;

class AnnoncePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('annonces.view');
    }

    public function view(User $user, Annonce $annonce): bool
    {
        if (! $user->hasPermission('annonces.view')) {
            return false;
        }

        return $annonce->faculte_id === null || $user->peutAccederFaculte((int) $annonce->faculte_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('annonces.create');
    }

    public function update(User $user, Annonce $annonce): bool
    {
        return $user->hasPermission('annonces.update')
            && ($annonce->faculte_id === null || $user->peutAccederFaculte((int) $annonce->faculte_id));
    }

    public function delete(User $user, Annonce $annonce): bool
    {
        return $user->hasPermission('annonces.delete');
    }

    public function publish(User $user, Annonce $annonce): bool
    {
        return $user->hasPermission('annonces.publish')
            && ($annonce->faculte_id === null || $user->peutAccederFaculte((int) $annonce->faculte_id));
    }
}
