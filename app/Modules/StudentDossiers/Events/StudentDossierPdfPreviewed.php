<?php

namespace App\Modules\StudentDossiers\Events;

use App\Modules\StudentDossiers\Models\GeneratedDocument;
use Illuminate\Foundation\Events\Dispatchable;

/** Page de prévisualisation du PDF consultée. */
class StudentDossierPdfPreviewed
{
    use Dispatchable;

    public function __construct(public readonly GeneratedDocument $document) {}
}
