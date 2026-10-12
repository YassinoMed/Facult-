<?php

namespace App\Modules\OnlineRequests\Policies;

use App\Models\User;
use App\Modules\OnlineRequests\Models\OnlineRequest;

/**
 * Demandes en ligne (§14-15) : permission RBAC + isolation faculté
 * (snapshot `faculte_id` à la soumission, repli étudiant sinon).
 */
class OnlineRequestPolicy
{
    private function perimetre(User $user, OnlineRequest $demande): ?int
    {
        $demande->loadMissing('etudiant');

        return (int) ($demande->faculte_id ?? $demande->etudiant->faculte_id);
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('demandes.view');
    }

    public function view(User $user, OnlineRequest $demande): bool
    {
        return $user->hasPermission('demandes.view')
            && $user->peutAccederFaculte($this->perimetre($user, $demande));
    }

    public function process(User $user, OnlineRequest $demande): bool
    {
        return $user->hasPermission('demandes.process')
            && $user->peutAccederFaculte($this->perimetre($user, $demande));
    }

    public function assign(User $user, OnlineRequest $demande): bool
    {
        return $user->hasPermission('demandes.assign')
            && $user->peutAccederFaculte($this->perimetre($user, $demande));
    }

    public function approve(User $user, OnlineRequest $demande): bool
    {
        return $user->hasPermission('demandes.approve')
            && $user->peutAccederFaculte($this->perimetre($user, $demande));
    }

    public function reject(User $user, OnlineRequest $demande): bool
    {
        return $user->hasPermission('demandes.reject')
            && $user->peutAccederFaculte($this->perimetre($user, $demande));
    }

    public function downloadPiece(User $user, OnlineRequest $demande): bool
    {
        return $user->hasPermission('demandes.documents.download')
            && $user->peutAccederFaculte($this->perimetre($user, $demande));
    }

    public function genererPdf(User $user, OnlineRequest $demande): bool
    {
        return $user->hasPermission('demandes.pdf')
            && $user->peutAccederFaculte($this->perimetre($user, $demande));
    }
}
