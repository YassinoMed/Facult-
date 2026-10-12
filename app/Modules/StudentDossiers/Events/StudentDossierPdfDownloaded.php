<?php

namespace App\Modules\StudentDossiers\Events;

use App\Modules\StudentDossiers\Models\GeneratedDocument;
use Illuminate\Foundation\Events\Dispatchable;

/** PDF téléchargé (Content-Disposition attachment). */
class StudentDossierPdfDownloaded
{
    use Dispatchable;

    public function __construct(public readonly GeneratedDocument $document) {}
}
