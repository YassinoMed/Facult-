<?php

namespace App\Modules\StudentDossiers\Jobs;

use App\Modules\StudentDossiers\Contracts\IdentityDocumentExtractorInterface;
use App\Modules\StudentDossiers\Models\StudentDocument;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Extraction OCR différée (§11/§17) : ne bloque jamais HTTP.
 * Fail-soft total : sans implémentation branchée du contrat, le job
 * se termine silencieusement. L'OCR n'est JAMAIS une preuve
 * d'identité — le résultat reste soumis à validation humaine.
 */
class OCRProcessingJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $documentId) {}

    public function handle(): void
    {
        if (! app()->bound(IdentityDocumentExtractorInterface::class)) {
            Log::info('ocr.extraction.ignoree', ['document_id' => $this->documentId]);

            return;
        }

        $document = StudentDocument::find($this->documentId);

        if ($document === null) {
            return;
        }

        $extracteur = app(IdentityDocumentExtractorInterface::class);

        $resultat = $extracteur->extraire([
            'storage_path' => $document->storage_path,
            'mime_type' => $document->mime_type,
            'category' => $document->category,
        ]);

        // Extension : persister IdentityExtractionResult + notifier la
        // scolarité pour validation humaine. Aucune écriture automatique
        // sur le profil étudiant à ce stade.
        Log::info('ocr.extraction.terminee', [
            'document_id' => $document->id,
            'champs' => array_keys($resultat),
        ]);
    }
}
