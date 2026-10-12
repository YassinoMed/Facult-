<?php

namespace App\Modules\StudentDossiers\Listeners;

use App\Modules\StudentDossiers\Events\DocumentValidated;
use App\Services\AuditService;

class AuditDocumentValidation
{
    public function __construct(private readonly AuditService $audit) {}

    public function handle(DocumentValidated $event): void
    {
        $document = $event->document;

        $this->audit->tracer(
            $document->dossier->etudiant,
            'student_document.validated',
            'student_document',
            $document->id,
            ['statut' => 'pending'],
            ['statut' => 'validated'],
        );
    }
}
