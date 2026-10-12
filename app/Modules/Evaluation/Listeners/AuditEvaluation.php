<?php

namespace App\Modules\Evaluation\Listeners;

use App\Modules\Evaluation\Events\EvaluationChanged;
use Illuminate\Support\Facades\Log;

class AuditEvaluation
{
    public function handle(EvaluationChanged $event): void
    {
        Log::info('evaluation.'.$event->typeChangement, [
            'entite_id' => $event->entiteId,
            'acteur_id' => auth()->id(),
        ]);
    }
}
