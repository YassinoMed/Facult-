<?php

namespace App\Modules\Finances\Events;

use Illuminate\Foundation\Events\Dispatchable;

class RemboursementTraite
{
    use Dispatchable;

    public function __construct(
        public readonly int $remboursementId,
        public readonly string $cible,
    ) {}
}
