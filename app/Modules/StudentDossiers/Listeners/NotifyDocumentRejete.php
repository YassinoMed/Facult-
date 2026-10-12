<?php

namespace App\Modules\StudentDossiers\Listeners;

use App\Modules\StudentDossiers\Events\DocumentRejected;
use App\Services\NotificationService;
use Throwable;

/**
 * Notification in-app de rejet de pièce (LOOP 11/12) : l'étudiant découvre
 * le rejet sans devoir consulter son dossier. Fail-soft : une panne de
 * notification ne doit jamais bloquer le rejet métier. Contenu sans PII
 * au-delà de ce que l'étudiant possède déjà (type de pièce + motif).
 */
class NotifyDocumentRejete
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(DocumentRejected $event): void
    {
        try {
            $document = $event->document;
            $etudiant = $document->dossier->etudiant;

            if ($etudiant === null) {
                return;
            }

            // Clé de dédup portée par la version courante (LOOP 12) : un
            // event rejoué sur le même rejet ne duplique pas, mais un
            // NOUVEAU rejet après remplacement (version +1) notifie bien.
            $version = (int) $document->versions()->max('version');

            $this->notifications->notifierEtudiant(
                $etudiant,
                'document.rejete',
                'Pièce rejetée par la scolarité',
                "Votre pièce « {$document->document_type} » a été rejetée".($document->rejected_reason ? " : {$document->rejected_reason}" : '').
                    '. Déposez une nouvelle version depuis votre dossier.',
                route('etudiant.dossier.show'),
                ['document_id' => $document->id, 'document_type' => $document->document_type],
                1,
                "document.rejete:{$document->id}:v{$version}",
            );
        } catch (Throwable) {
            // Fail-soft : le rejet et son audit restent la source de vérité.
        }
    }
}
