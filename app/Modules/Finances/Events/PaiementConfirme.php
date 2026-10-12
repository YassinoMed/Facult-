<?php

namespace App\Modules\Finances\Events;

use Illuminate\Foundation\Events\Dispatchable;

class PaiementConfirme
{
    use Dispatchable;

    public function __construct(public readonly int $paiementId) {}
}
