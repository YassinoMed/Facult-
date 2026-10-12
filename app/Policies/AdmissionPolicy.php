<?php

namespace App\Policies;

use App\Models\Admission;
use App\Models\User;

/**
 * Admission : permission par action ; le périmètre faculté (étudiant +
 * programme cible) est appliqué au contrôleur (scope → 404, convention
 * des contrôleurs du noyau).
 */
class AdmissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('admissions.view');
    }

    public function view(User $user, Admission $admission): bool
    {
        return $user->hasPermission('admissions.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('admissions.manage');
    }

    public function delete(User $user, Admission $admission): bool
    {
        return $user->hasPermission('admissions.manage');
    }
}
