<?php

namespace App\Modules\OnlineRequests\Events;

use App\Modules\OnlineRequests\Models\OnlineRequest;
use Illuminate\Foundation\Events\Dispatchable;

class OnlineRequestApproved
{
    use Dispatchable;

    public function __construct(public readonly OnlineRequest $demande) {}
}
