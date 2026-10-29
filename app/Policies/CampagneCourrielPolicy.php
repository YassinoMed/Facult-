<?php

namespace App\Policies;

use App\Models\CampagneCourriel;
use App\Models\User;
use App\Support\CampagnePerimetre;

class CampagneCourrielPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('campagnes.view');
    }

    public function view(User $user, CampagneCourriel $campagne): bool
    {
        // Périmètre faculté (LOOP-SECURITY-REMEDIATION-01) : un
        // admin_faculte ne voit pas les campagnes des autres facultés ni
        // les campagnes globales.
        return $user->hasPermission('campagnes.view')
            && CampagnePerimetre::campagneAutorisee($campagne, $user->faculteRestreinteId());
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('campagnes.manage');
    }

    public function update(User $user, CampagneCourriel $campagne): bool
    {
        return $user->hasPermission('campagnes.manage')
            && CampagnePerimetre::campagneAutorisee($campagne, $user->faculteRestreinteId())
            && $campagne->peutEtreModifiee();
    }

    public function envoyer(User $user, CampagneCourriel $campagne): bool
    {
        return $user->hasPermission('campagnes.manage')
            && $user->hasPermission('mail.send')
            && CampagnePerimetre::campagneAutorisee($campagne, $user->faculteRestreinteId())
            && $campagne->peutEtreEnvoyee();
    }

    public function annuler(User $user, CampagneCourriel $campagne): bool
    {
        return $user->hasPermission('campagnes.manage')
            && CampagnePerimetre::campagneAutorisee($campagne, $user->faculteRestreinteId())
            && $campagne->peutEtreAnnulee();
    }

    public function relancer(User $user, CampagneCourriel $campagne): bool
    {
        return $user->hasPermission('campagnes.manage')
            && $user->hasPermission('mail.send')
            && CampagnePerimetre::campagneAutorisee($campagne, $user->faculteRestreinteId())
            && $campagne->peutEtreRelancee();
    }

    public function delete(User $user, CampagneCourriel $campagne): bool
    {
        return $user->hasPermission('campagnes.manage')
            && CampagnePerimetre::campagneAutorisee($campagne, $user->faculteRestreinteId())
            && $campagne->statut === CampagneCourriel::STATUT_BROUILLON;
    }
}
