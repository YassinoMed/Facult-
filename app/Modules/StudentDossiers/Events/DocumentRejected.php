<?php

namespace App\Modules\StudentDossiers\Events;

use App\Modules\StudentDossiers\Models\StudentDocument;
use Illuminate\Foundation\Events\Dispatchable;

/** Document rejeté (motif obligatoire, stocké sur le modèle). */
class DocumentRejected
{
    use Dispatchable;

    public function __construct(public readonly StudentDocument $document) {}
}
