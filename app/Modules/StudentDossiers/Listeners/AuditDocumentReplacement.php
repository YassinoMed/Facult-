<?php

namespace App\Modules\StudentDossiers\Listeners;

use App\Modules\StudentDossiers\Events\DocumentReplaced;
use App\Services\AuditService;

class AuditDocumentReplacement
{
    public function __construct(private readonly AuditService $audit) {}

    public function handle(DocumentReplaced $event): void
    {
        $document = $event->document;

        $this->audit->tracer(
            $document->dossier->etudiant,
            'student_document.replaced',
            'student_document',
            $document->id,
            null,
            ['version' => $document->versions->max('version')],
        );
    }
}
