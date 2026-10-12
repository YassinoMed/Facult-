<?php

namespace App\Modules\Schedule\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Créneau modifié (§14) : payload minimal (ids + type), jamais de PII.
 * Prêt pour `ShouldBroadcast` : le jour du WebSocket, implémenter
 * l'interface + canal privé par groupe, sans toucher les émetteurs.
 */
class ScheduleChanged
{
    use Dispatchable;

    public function __construct(
        public readonly int $scheduleId,
        public readonly int $slotId,
        public readonly string $typeChangement,
    ) {}
}
