<?php

namespace App\Modules\Attendance\Policies;

use App\Models\Etudiant;
use App\Models\User;
use App\Modules\Attendance\Models\Justification;

class JustificationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('attendance.view');
    }

    public function view(User $user, Justification $justification): bool
    {
        return $user->hasPermission('attendance.view')
            && $user->peutAccederFaculte((int) $justification->etudiant->faculte_id);
    }

    public function validate(User $user, Justification $justification): bool
    {
        return $user->hasPermission('attendance.justify')
            && $user->peutAccederFaculte((int) $justification->etudiant->faculte_id);
    }

    public function deposit(User $user, Etudiant $etudiant): bool
    {
        return $user->hasPermission('attendance.create')
            && $user->peutAccederFaculte((int) $etudiant->faculte_id);
    }
}
