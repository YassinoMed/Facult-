<?php

namespace App\Modules\StudentDossiers\Listeners;

use App\Modules\StudentDossiers\Events\DocumentUploaded;
use App\Modules\StudentDossiers\Jobs\OCRProcessingJob;

/**
 * Chaîne OCR future (§11) : DocumentUploaded → OCRProcessingJob →
 * IdentityExtractionResult → validation humaine. Déclenché uniquement
 * pour les documents d'identité ET si l'extraction est activée
 * (config student-dossiers.ocr.enabled, false par défaut).
 */
class LancerExtractionOcr
{
    public function handle(DocumentUploaded $event): void
    {
        $document = $event->document;

        /** @var array<int, string> $categories */
        $categories = config('student-dossiers.ocr.categories', []);

        if (! config('student-dossiers.ocr.enabled', false)) {
            return;
        }

        if (! in_array($document->category, $categories, true)) {
            return;
        }

        OCRProcessingJob::dispatch($document->id);
    }
}
