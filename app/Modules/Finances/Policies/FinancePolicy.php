<?php

namespace App\Modules\Finances\Policies;

use App\Models\User;
use App\Modules\Finances\Models\Facture;
use App\Modules\Finances\Models\Paiement;
use App\Modules\Finances\Models\Remboursement;
use App\Modules\Finances\Models\Tarif;

class FinancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('finances.view');
    }

    public function view(User $user, Facture|Paiement|Remboursement|Tarif $objet): bool
    {
        if (! $user->hasPermission('finances.view')) {
            return false;
        }

        $faculteId = match (true) {
            $objet instanceof Facture => $objet->etudiant?->faculte_id,
            $objet instanceof Paiement => $objet->facture?->etudiant?->faculte_id,
            $objet instanceof Remboursement => $objet->paiement?->facture?->etudiant?->faculte_id,
            default => null,
        };

        return $faculteId === null || $user->peutAccederFaculte((int) $faculteId);
    }

    public function manage(User $user): bool
    {
        return $user->hasPermission('finances.manage');
    }

    public function confirm(User $user, Paiement $paiement): bool
    {
        return $user->hasPermission('finances.confirm')
            && ($paiement->facture?->etudiant?->faculte_id === null
                || $user->peutAccederFaculte((int) $paiement->facture->etudiant->faculte_id));
    }
}
