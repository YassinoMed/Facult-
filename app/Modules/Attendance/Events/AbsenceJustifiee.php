<?php

namespace App\Modules\Attendance\Events;

use Illuminate\Foundation\Events\Dispatchable;

class AbsenceJustifiee
{
    use Dispatchable;

    public function __construct(public readonly int $justificationId) {}
}
