<?php

namespace App\Modules\Sentinel\Policies;

use App\Models\User;
use App\Modules\Sentinel\Models\SentinelIncident;

/**
 * Platform Sentinel : permission RBAC + isolation faculté quand
 * l'incident est rattaché à une faculté (sinon transverse, visible).
 */
class SentinelIncidentPolicy
{
    public function viewAny(User $utilisateur): bool
    {
        return $utilisateur->hasPermission('sentinel.view');
    }

    public function view(User $utilisateur, SentinelIncident $incident): bool
    {
        if (! $utilisateur->hasPermission('sentinel.view')) {
            return false;
        }

        if ($incident->faculte_id === null) {
            return true;
        }

        return $utilisateur->peutAccederFaculte((int) $incident->faculte_id);
    }

    public function manage(User $utilisateur, ?SentinelIncident $incident = null): bool
    {
        if (! $utilisateur->hasPermission('sentinel.manage')) {
            return false;
        }

        if ($incident === null || $incident->faculte_id === null) {
            return true;
        }

        return $utilisateur->peutAccederFaculte((int) $incident->faculte_id);
    }
}
