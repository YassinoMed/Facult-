<?php

namespace App\Modules\Announcements\Events;

use Illuminate\Foundation\Events\Dispatchable;

class CampagneEnvoyee
{
    use Dispatchable;

    public function __construct(public readonly int $campagneId) {}
}
