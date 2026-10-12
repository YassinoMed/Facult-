<?php

namespace App\Modules\Attendance\Policies;

use App\Models\User;
use App\Modules\Attendance\Models\AttendanceTemplate;

/**
 * Module Attendance (§10-12) : politique d'accès aux modèles de fiches de présence.
 * Les modèles système ne peuvent JAMAIS être supprimés.
 */
class AttendanceTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('attendance.templates.manage')
            || $user->hasPermission('attendance.templates.use')
            || $user->hasPermission('attendance.view');
    }

    public function view(User $user, AttendanceTemplate $template): bool
    {
        if ($template->faculte_id !== null && ! $user->peutAccederFaculte((int) $template->faculte_id)) {
            abort(404, 'Accès non autorisé à cette faculté.');
        }

        return $user->hasPermission('attendance.templates.manage')
            || $user->hasPermission('attendance.templates.use')
            || $user->hasPermission('attendance.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('attendance.templates.manage');
    }

    public function update(User $user, AttendanceTemplate $template): bool
    {
        if ($template->faculte_id !== null && ! $user->peutAccederFaculte((int) $template->faculte_id)) {
            abort(404, 'Accès non autorisé à cette faculté.');
        }

        return $user->hasPermission('attendance.templates.manage');
    }

    public function delete(User $user, AttendanceTemplate $template): bool
    {
        if ($template->faculte_id !== null && ! $user->peutAccederFaculte((int) $template->faculte_id)) {
            abort(404, 'Accès non autorisé à cette faculté.');
        }

        // Règle absolue : un modèle système (is_system = true) ne peut jamais être supprimé.
        if ($template->is_system) {
            return false;
        }

        return $user->hasPermission('attendance.templates.manage');
    }

    public function duplicate(User $user, AttendanceTemplate $template): bool
    {
        if ($template->faculte_id !== null && ! $user->peutAccederFaculte((int) $template->faculte_id)) {
            abort(404, 'Accès non autorisé à cette faculté.');
        }

        return $user->hasPermission('attendance.templates.manage');
    }

    public function toggle(User $user, AttendanceTemplate $template): bool
    {
        if ($template->faculte_id !== null && ! $user->peutAccederFaculte((int) $template->faculte_id)) {
            abort(404, 'Accès non autorisé à cette faculté.');
        }

        return $user->hasPermission('attendance.templates.manage');
    }
}
