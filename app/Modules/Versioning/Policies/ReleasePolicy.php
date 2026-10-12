<?php

namespace App\Modules\Versioning\Policies;

use App\Models\User;
use App\Modules\Versioning\Models\Release;

/**
 * Policy de sécurité pour les releases et le versionnage système.
 *
 * Règles :
 * - Les administrateurs avec périmètre faculté restreint n'ont AUCUN accès (infrastructure globale).
 * - Contrôles stricts basés sur les permissions RBAC.
 */
class ReleasePolicy
{
    /**
     * Garde globale : refuse tout accès si l'utilisateur est restreint à une faculté.
     */
    private function accesGlobalAutorise(User $user): bool
    {
        return $user->faculteRestreinteId() === null;
    }

    public function viewAny(User $user): bool
    {
        return $this->accesGlobalAutorise($user)
            && $user->hasPermission('versioning.view');
    }

    public function view(User $user, Release $release): bool
    {
        return $this->accesGlobalAutorise($user)
            && $user->hasPermission('versioning.view');
    }

    public function create(User $user): bool
    {
        return $this->accesGlobalAutorise($user)
            && $user->hasPermission('versioning.create');
    }

    public function update(User $user, Release $release): bool
    {
        return $this->accesGlobalAutorise($user)
            && $user->hasPermission('versioning.create')
            && ! $release->estImmutable();
    }

    public function approve(User $user, Release $release): bool
    {
        return $this->accesGlobalAutorise($user)
            && $user->hasPermission('versioning.approve')
            && $release->peutTransiter(Release::STATUT_APPROUVEE);
    }

    public function deploy(User $user, Release $release): bool
    {
        return $this->accesGlobalAutorise($user)
            && $user->hasPermission('versioning.deploy')
            && in_array($release->statut, [
                Release::STATUT_PREPAREE,
                Release::STATUT_TESTEE,
                Release::STATUT_APPROUVEE,
            ], true);
    }

    public function rollback(User $user, Release $release): bool
    {
        return $this->accesGlobalAutorise($user)
            && $user->hasPermission('versioning.rollback');
    }

    public function delete(User $user, Release $release): bool
    {
        return $this->accesGlobalAutorise($user)
            && $user->hasPermission('versioning.manage')
            && $release->statut === Release::STATUT_BROUILLON;
    }
}
