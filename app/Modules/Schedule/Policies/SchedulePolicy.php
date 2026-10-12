<?php

namespace App\Modules\Schedule\Policies;

use App\Models\User;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;

/**
 * Emplois du temps : permission + isolation faculté via le groupe.
 */
class SchedulePolicy
{
    private function faculteDuPlanning(Schedule $schedule): int
    {
        $schedule->loadMissing('groupe');

        return (int) $schedule->groupe->faculte_id;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('schedules.view');
    }

    public function view(User $user, Schedule $schedule): bool
    {
        return $user->hasPermission('schedules.view')
            && $user->peutAccederFaculte($this->faculteDuPlanning($schedule));
    }

    public function manage(User $user, Schedule $schedule): bool
    {
        return $user->hasPermission('schedules.manage')
            && $user->peutAccederFaculte($this->faculteDuPlanning($schedule));
    }

    public function manageSlot(User $user, ScheduleSlot $slot): bool
    {
        $slot->loadMissing('schedule.groupe');

        return $user->hasPermission('schedules.manage')
            && $user->peutAccederFaculte((int) $slot->schedule->groupe->faculte_id);
    }
}
