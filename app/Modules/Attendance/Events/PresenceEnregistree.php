<?php

namespace App\Modules\Attendance\Events;

use App\Modules\Attendance\Models\Presence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Module Attendance (§6) : annoncé à chaque relevé, consommé par
 * l'audit centralisé — le service ne connaît pas l'audit.
 */
class PresenceEnregistree
{
    use Dispatchable;

    public function __construct(public readonly Presence $presence) {}
}
