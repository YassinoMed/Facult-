<?php

namespace App\Modules\StudentDossiers\Events;

use App\Modules\StudentDossiers\Models\StudentDocument;
use Illuminate\Foundation\Events\Dispatchable;

/** Document validé par la scolarité. */
class DocumentValidated
{
    use Dispatchable;

    public function __construct(public readonly StudentDocument $document) {}
}
