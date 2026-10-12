<?php

namespace App\Modules\StudentDossiers\Listeners;

use App\Modules\StudentDossiers\Events\DocumentArchived;
use App\Services\AuditService;

class AuditDocumentArchiving
{
    public function __construct(private readonly AuditService $audit) {}

    public function handle(DocumentArchived $event): void
    {
        $document = $event->document;

        $this->audit->tracer(
            $document->dossier->etudiant,
            'student_document.archived',
            'student_document',
            $document->id,
        );
    }
}
