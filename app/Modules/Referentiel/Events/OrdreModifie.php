<?php

namespace App\Modules\Referentiel\Events;

use Illuminate\Foundation\Events\Dispatchable;

class OrdreModifie
{
    use Dispatchable;

    public function __construct(
        public readonly string $type,
        public readonly int $nombre,
        public readonly int $auteurId,
    ) {}
}
