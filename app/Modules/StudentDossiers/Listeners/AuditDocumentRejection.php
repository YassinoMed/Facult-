<?php

namespace App\Modules\StudentDossiers\Listeners;

use App\Modules\StudentDossiers\Events\DocumentRejected;
use App\Services\AuditService;

class AuditDocumentRejection
{
    public function __construct(private readonly AuditService $audit) {}

    public function handle(DocumentRejected $event): void
    {
        $document = $event->document;

        $this->audit->tracer(
            $document->dossier->etudiant,
            'student_document.rejected',
            'student_document',
            $document->id,
            ['statut' => 'pending'],
            ['statut' => 'rejected'],
        );
    }
}
