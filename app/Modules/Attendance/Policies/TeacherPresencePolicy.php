<?php

namespace App\Modules\Attendance\Policies;

use App\Models\User;
use App\Modules\Attendance\Models\TeacherPresence;

/**
 * Policy de sécurité pour les présences des enseignants :
 * Isolation stricte par faculté (anti-IDOR/BOLA) + vérification des permissions RBAC.
 */
class TeacherPresencePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('attendance.view');
    }

    public function view(User $user, TeacherPresence $presence): bool
    {
        if (! $user->hasPermission('attendance.view')) {
            return false;
        }

        $faculteId = $this->extraireFaculteId($presence);

        return $faculteId === null || $user->peutAccederFaculte($faculteId);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('attendance.create');
    }

    public function valider(User $user, TeacherPresence $presence): bool
    {
        if (! $user->hasPermission('attendance.create')) {
            return false;
        }

        $faculteId = $this->extraireFaculteId($presence);

        return $faculteId === null || $user->peutAccederFaculte($faculteId);
    }

    public function corriger(User $user, TeacherPresence $presence): bool
    {
        if (! $user->hasPermission('attendance.create')) {
            return false;
        }

        $faculteId = $this->extraireFaculteId($presence);

        return $faculteId === null || $user->peutAccederFaculte($faculteId);
    }

    private function extraireFaculteId(TeacherPresence $presence): ?int
    {
        // Périmètre principal : faculté du groupe rattaché à l'emploi du temps
        $faculteGroupe = $presence->slot?->schedule?->groupe?->faculte_id;
        if ($faculteGroupe !== null) {
            return (int) $faculteGroupe;
        }

        // Périmètre secondaire : faculté du département de l'enseignant
        $faculteEnseignant = $presence->teacher?->departement?->faculte_id;
        if ($faculteEnseignant !== null) {
            return (int) $faculteEnseignant;
        }

        return null;
    }
}
