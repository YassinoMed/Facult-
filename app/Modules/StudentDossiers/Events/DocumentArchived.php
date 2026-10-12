<?php

namespace App\Modules\StudentDossiers\Events;

use App\Modules\StudentDossiers\Models\StudentDocument;
use Illuminate\Foundation\Events\Dispatchable;

/** Document archivé (fin de vie, conservé). */
class DocumentArchived
{
    use Dispatchable;

    public function __construct(public readonly StudentDocument $document) {}
}
