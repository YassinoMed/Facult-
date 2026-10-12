<?php

namespace App\Modules\Attendance\Events;

use App\Modules\Attendance\Models\Presence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Module Attendance : annoncé à chaque suppression de relevé,
 * consommé par l'audit centralisé — symétrique de
 * PresenceEnregistree (une suppression silencieuse est interdite).
 */
class PresenceSupprimee
{
    use Dispatchable;

    public function __construct(public readonly Presence $presence) {}
}
