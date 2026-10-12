<?php

namespace App\Modules\Schedule\Services;

use App\Modules\Schedule\Events\ScheduleChanged;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleException;
use App\Modules\Schedule\Models\ScheduleSlot;
use Illuminate\Support\Facades\Log;

/**
 * Notifications de planning (§15) : message destiné aux étudiants du
 * groupe concerné. Canaux (base, email, broadcast) à brancher sur
 * l'event `ScheduleChanged` — en attendant : log sans PII.
 */
class ScheduleNotificationService
{
    public function notifier(ScheduleSlot $slot, string $message): int
    {
        $slot->loadMissing('schedule.groupe');

        $destinataires = $slot->schedule->groupe->etudiants()->count();

        ScheduleChanged::dispatch($slot->schedule_id, $slot->id, 'notifie');

        Log::info('schedule.notification', [
            'schedule_id' => $slot->schedule_id,
            'slot_id' => $slot->id,
            'destinataires' => $destinataires,
            'message' => $message,
        ]);

        $this->oublierVersion($slot->schedule_id);

        return $destinataires;
    }

    public function version(Schedule $schedule): string
    {
        $cle = (string) config('schedule.version_prefixe', 'schedule:version:').$schedule->id;

        return (string) cache()->remember(
            $cle,
            (int) config('schedule.version_ttl', 3600),
            fn () => $this->calculerVersion($schedule)
        );
    }

    public function oublierVersion(int $scheduleId): void
    {
        cache()->forget((string) config('schedule.version_prefixe', 'schedule:version:').$scheduleId);
    }

    private function calculerVersion(Schedule $schedule): string
    {
        $horodatage = ScheduleSlot::where('schedule_id', $schedule->id)->max('updated_at')
            ?? $schedule->updated_at;

        $exceptions = ScheduleException::whereHas(
            'slot',
            fn ($q) => $q->where('schedule_id', $schedule->id)
        )->max('updated_at');

        if ($exceptions !== null && $exceptions > $horodatage) {
            $horodatage = $exceptions;
        }

        return (string) (strtotime((string) $horodatage) ?: time());
    }
}
