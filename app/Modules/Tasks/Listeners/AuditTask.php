<?php

namespace App\Modules\Tasks\Listeners;

use App\Modules\Tasks\Events\TaskChanged;
use Illuminate\Support\Facades\Log;

class AuditTask
{
    public function handle(TaskChanged $event): void
    {
        Log::info('task.'.$event->typeChangement, ['task_id' => $event->taskId, 'acteur_id' => auth()->id()]);
    }
}
