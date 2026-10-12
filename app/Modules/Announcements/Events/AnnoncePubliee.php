<?php

namespace App\Modules\Announcements\Events;

use Illuminate\Foundation\Events\Dispatchable;

class AnnoncePubliee
{
    use Dispatchable;

    public function __construct(public readonly int $annonceId) {}
}
