<?php

namespace App\Modules\Evaluation\Events;

use Illuminate\Foundation\Events\Dispatchable;

class EvaluationChanged
{
    use Dispatchable;

    public function __construct(
        public readonly string $typeChangement,
        public readonly int $entiteId,
    ) {}
}
