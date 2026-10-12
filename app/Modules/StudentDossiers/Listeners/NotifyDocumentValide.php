<?php

namespace App\Modules\StudentDossiers\Listeners;

use App\Modules\StudentDossiers\Events\DocumentValidated;
use App\Services\NotificationService;

/**
 * Document validé → l'étudiant propriétaire est notifié (§5).
 */
class NotifyDocumentValide
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    public function handle(DocumentValidated $event): void
    {
        $document = $event->document;
        $document->loadMissing('dossier.etudiant');

        // Phase F : titre libre potentiellement médical → libellé neutre
        // en notification (non-divulgation, §21).
        $medical = $document->category === 'medical';

        $this->notifications->notifierEtudiant(
            $document->dossier->etudiant,
            'document.validated',
            'Document validé',
            $medical
                ? 'Votre document médical a été traité par la scolarité.'
                : "Votre document « {$document->title} » a été validé par la scolarité.",
            route('etudiant.dossier.show'),
            $medical ? [] : ['titre' => $document->title],
        );
    }
}
