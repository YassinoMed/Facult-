<?php

namespace App\Modules\StudentDossiers\Events;

use App\Modules\StudentDossiers\Models\StudentDocument;
use Illuminate\Foundation\Events\Dispatchable;

/** Document téléversé (v1 ou remplacement initial). */
class DocumentUploaded
{
    use Dispatchable;

    public function __construct(public readonly StudentDocument $document) {}
}
