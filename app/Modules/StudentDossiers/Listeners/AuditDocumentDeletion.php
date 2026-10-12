<?php

namespace App\Modules\StudentDossiers\Listeners;

use App\Models\Etudiant;
use App\Modules\StudentDossiers\Events\DocumentDeleted;
use App\Services\AuditService;

class AuditDocumentDeletion
{
    public function __construct(private readonly AuditService $audit) {}

    public function handle(DocumentDeleted $event): void
    {
        $etudiant = Etudiant::find($event->etudiantId);

        if ($etudiant === null) {
            return;
        }

        // Phase F : catégorie médicale → audit sans titre (§22).
        $medical = ($event->categorie ?? null) === 'medical';

        $this->audit->tracer(
            $etudiant,
            'student_document.deleted',
            'student_document',
            null,
            $medical
                ? ['dossier_id' => $event->dossierId]
                : ['titre' => $event->titre, 'dossier_id' => $event->dossierId],
        );
    }
}
