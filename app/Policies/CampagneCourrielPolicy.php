<?php

namespace App\Policies;

use App\Models\CampagneCourriel;
use App\Models\User;

class CampagneCourrielPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('campagnes.view');
    }

    public function view(User $user, CampagneCourriel $campagne): bool
    {
        return $user->hasPermission('campagnes.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('campagnes.manage');
    }

    public function update(User $user, CampagneCourriel $campagne): bool
    {
        return $user->hasPermission('campagnes.manage') && $campagne->peutEtreModifiee();
    }

    public function envoyer(User $user, CampagneCourriel $campagne): bool
    {
        return $user->hasPermission('campagnes.manage') && $user->hasPermission('mail.send') && $campagne->peutEtreEnvoyee();
    }

    public function annuler(User $user, CampagneCourriel $campagne): bool
    {
        return $user->hasPermission('campagnes.manage') && $campagne->peutEtreAnnulee();
    }

    public function relancer(User $user, CampagneCourriel $campagne): bool
    {
        return $user->hasPermission('campagnes.manage') && $user->hasPermission('mail.send') && $campagne->peutEtreRelancee();
    }

    public function delete(User $user, CampagneCourriel $campagne): bool
    {
        return $user->hasPermission('campagnes.manage') && $campagne->statut === CampagneCourriel::STATUT_BROUILLON;
    }
}
