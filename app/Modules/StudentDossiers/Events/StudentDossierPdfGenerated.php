<?php

namespace App\Modules\StudentDossiers\Events;

use App\Modules\StudentDossiers\Models\GeneratedDocument;
use Illuminate\Foundation\Events\Dispatchable;

/** PDF du dossier généré et archivé sur le disque privé. */
class StudentDossierPdfGenerated
{
    use Dispatchable;

    public function __construct(public readonly GeneratedDocument $document) {}
}
