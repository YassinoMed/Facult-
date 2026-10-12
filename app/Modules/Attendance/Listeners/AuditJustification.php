<?php

namespace App\Modules\Attendance\Listeners;

use App\Modules\Attendance\Events\AbsenceJustifiee;
use Illuminate\Support\Facades\Log;

class AuditJustification
{
    public function handle(AbsenceJustifiee $event): void
    {
        Log::info('absence.justifiee', ['justification_id' => $event->justificationId, 'acteur_id' => auth()->id()]);
    }
}
