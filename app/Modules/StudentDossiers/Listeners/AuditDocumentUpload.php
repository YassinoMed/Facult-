<?php

namespace App\Modules\StudentDossiers\Listeners;

use App\Modules\StudentDossiers\Events\DocumentUploaded;
use App\Services\AuditService;

/**
 * Audit transverse (§10/§15) : métadonnées uniquement (titre,
 * catégorie, type) — jamais contenu, mot de passe, token ou secret.
 */
class AuditDocumentUpload
{
    public function __construct(private readonly AuditService $audit) {}

    public function handle(DocumentUploaded $event): void
    {
        $document = $event->document;

        // Phase F : catégorie médicale → audit sans titre (id + statut
        // suffisent ; le titre libre peut contenir du médical, §22).
        $medical = $document->category === 'medical';

        $this->audit->tracer(
            $document->dossier->etudiant,
            'student_document.uploaded',
            'student_document',
            $document->id,
            null,
            $medical
                ? ['categorie' => $document->category, 'type' => $document->document_type]
                : ['titre' => $document->title, 'categorie' => $document->category, 'type' => $document->document_type],
        );
    }
}
