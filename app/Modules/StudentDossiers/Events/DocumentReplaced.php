<?php

namespace App\Modules\StudentDossiers\Events;

use App\Modules\StudentDossiers\Models\StudentDocument;
use Illuminate\Foundation\Events\Dispatchable;

/** Document remplacé : nouvelle version créée, historique conservé. */
class DocumentReplaced
{
    use Dispatchable;

    public function __construct(public readonly StudentDocument $document) {}
}
