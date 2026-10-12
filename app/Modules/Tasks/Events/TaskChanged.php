<?php

namespace App\Modules\Tasks\Events;

use Illuminate\Foundation\Events\Dispatchable;

class TaskChanged
{
    use Dispatchable;

    public function __construct(
        public readonly string $typeChangement,
        public readonly int $taskId,
    ) {}
}
