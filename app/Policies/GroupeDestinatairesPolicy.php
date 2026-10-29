<?php

namespace App\Policies;

use App\Models\GroupeDestinataires;
use App\Models\User;
use App\Support\CampagnePerimetre;

class GroupeDestinatairesPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('campagnes.view');
    }

    public function view(User $user, GroupeDestinataires $groupe): bool
    {
        // Périmètre faculté (LOOP-SECURITY-REMEDIATION-01).
        return $user->hasPermission('campagnes.view')
            && CampagnePerimetre::groupeAutorise($groupe, $user->faculteRestreinteId());
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('campagnes.manage');
    }

    public function update(User $user, GroupeDestinataires $groupe): bool
    {
        return $user->hasPermission('campagnes.manage')
            && CampagnePerimetre::groupeAutorise($groupe, $user->faculteRestreinteId())
            && ! $groupe->est_archive;
    }

    public function delete(User $user, GroupeDestinataires $groupe): bool
    {
        return $user->hasPermission('campagnes.manage')
            && CampagnePerimetre::groupeAutorise($groupe, $user->faculteRestreinteId());
    }
}
