<?php

namespace App\Modules\Finances\Policies;

use App\Models\User;
use App\Modules\Finances\Models\DossierPaiement;

/**
 * LOOP-UNIREGISTRE-INSCRIPTIONS-PAIEMENTS-01 : isolation des dossiers
 * de paiement annuels.
 *
 * - consultation : permission finance existante `finances.view` ;
 * - décision (prise en charge, validation, refus) : permission dédiée
 *   `finances.receipts.verify`, afin de ne pas élargir silencieusement
 *   `finances.confirm` (confirmation des paiements de factures) ;
 * - tout est re-scopé par faculté : un agent ne voit jamais le dossier
 *   d'un étudiant d'une autre faculté.
 */
class DossierPaiementPolicy
{
    private function dansPerimetre(User $user, DossierPaiement $dossier): bool
    {
        $etudiant = $dossier->inscription?->etudiant;

        return $etudiant !== null
            && $user->peutAccederFaculte((int) $etudiant->faculte_id);
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('finances.view');
    }

    public function view(User $user, DossierPaiement $dossier): bool
    {
        return $user->hasPermission('finances.view')
            && $this->dansPerimetre($user, $dossier);
    }

    public function verifier(User $user, DossierPaiement $dossier): bool
    {
        return $user->hasPermission('finances.receipts.verify')
            && $this->dansPerimetre($user, $dossier);
    }

    public function valider(User $user, DossierPaiement $dossier): bool
    {
        return $this->verifier($user, $dossier);
    }

    public function refuser(User $user, DossierPaiement $dossier): bool
    {
        return $this->verifier($user, $dossier);
    }
}
