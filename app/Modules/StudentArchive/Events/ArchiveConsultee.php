<?php

namespace App\Modules\StudentArchive\Events;

use Illuminate\Foundation\Events\Dispatchable;

class ArchiveConsultee
{
    use Dispatchable;

    public function __construct(
        public readonly int $etudiantId,
        public readonly int $acteurId,
        public readonly string $onglet,
    ) {}
}
