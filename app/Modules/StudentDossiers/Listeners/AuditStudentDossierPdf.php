<?php

namespace App\Modules\StudentDossiers\Listeners;

use App\Modules\StudentDossiers\Events\StudentDossierPdfDownloaded;
use App\Modules\StudentDossiers\Events\StudentDossierPdfGenerated;
use App\Modules\StudentDossiers\Events\StudentDossierPdfPreviewed;
use App\Modules\StudentDossiers\Events\StudentDossierPdfPrinted;
use App\Services\AuditService;

/**
 * Audit PDF (§18) : référence + type + version uniquement — jamais le
 * contenu du PDF, jamais de secret.
 */
class AuditStudentDossierPdf
{
    public function __construct(private readonly AuditService $audit) {}

    private function meta($document): array
    {
        return [
            'reference' => $document->reference,
            'type' => $document->type,
            'version' => $document->version,
        ];
    }

    public function generation(StudentDossierPdfGenerated $event): void
    {
        $document = $event->document;

        $this->audit->tracer(
            $document->dossier->etudiant,
            'student_dossier.pdf.generated',
            'generated_document',
            $document->id,
            null,
            $this->meta($document),
        );
    }

    public function preview(StudentDossierPdfPreviewed $event): void
    {
        $document = $event->document;

        $this->audit->tracer(
            $document->dossier->etudiant,
            'student_dossier.pdf.previewed',
            'generated_document',
            $document->id,
            null,
            $this->meta($document),
        );
    }

    public function download(StudentDossierPdfDownloaded $event): void
    {
        $document = $event->document;

        $this->audit->tracer(
            $document->dossier->etudiant,
            'student_dossier.pdf.downloaded',
            'generated_document',
            $document->id,
            null,
            $this->meta($document),
        );
    }

    public function print(StudentDossierPdfPrinted $event): void
    {
        $document = $event->document;

        $this->audit->tracer(
            $document->dossier->etudiant,
            'student_dossier.pdf.printed',
            'generated_document',
            $document->id,
            null,
            $this->meta($document),
        );
    }
}
