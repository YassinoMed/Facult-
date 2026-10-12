<?php

namespace App\Modules\OnlineRequests\Events;

use App\Modules\OnlineRequests\Models\OnlineRequest;
use Illuminate\Foundation\Events\Dispatchable;

class OnlineRequestCreated
{
    use Dispatchable;

    public function __construct(public readonly OnlineRequest $demande) {}
}
