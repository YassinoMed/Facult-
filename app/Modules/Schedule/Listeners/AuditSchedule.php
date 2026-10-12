<?php

namespace App\Modules\Schedule\Listeners;

use App\Modules\Schedule\Events\ScheduleChanged;
use Illuminate\Support\Facades\Log;

/**
 * Journal des modifications (§25) : ids + type uniquement, sans PII
 * ni secret. L'historique métier détaillé vit en `schedule_changes`.
 */
class AuditSchedule
{
    public function handle(ScheduleChanged $event): void
    {
        Log::info('schedule.slot.'.$event->typeChangement, [
            'schedule_id' => $event->scheduleId,
            'slot_id' => $event->slotId,
        ]);
    }
}
