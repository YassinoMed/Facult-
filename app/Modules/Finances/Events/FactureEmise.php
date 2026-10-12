<?php

namespace App\Modules\Finances\Events;

use Illuminate\Foundation\Events\Dispatchable;

class FactureEmise
{
    use Dispatchable;

    public function __construct(public readonly int $factureId) {}
}
