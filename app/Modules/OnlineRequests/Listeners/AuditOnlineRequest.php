<?php

namespace App\Modules\OnlineRequests\Listeners;

use App\Modules\OnlineRequests\Events\OnlineRequestApproved;
use App\Modules\OnlineRequests\Events\OnlineRequestCompleted;
use App\Modules\OnlineRequests\Events\OnlineRequestCreated;
use App\Modules\OnlineRequests\Events\OnlineRequestDocumentGenerated;
use App\Modules\OnlineRequests\Events\OnlineRequestRejected;
use App\Modules\OnlineRequests\Events\OnlineRequestSubmitted;
use App\Modules\OnlineRequests\Services\AuditDemandes;

/**
 * Audit des demandes (§10/§15) : référence + statut uniquement —
 * jamais contenu, mot de passe, token ou secret. Les canaux de
 * notification (email/SMS) se brancheront sur ces mêmes events.
 */
class AuditOnlineRequest
{
    public function __construct(private readonly AuditDemandes $audit) {}

    public function creation(OnlineRequestCreated $event): void
    {
        $this->audit->tracer($event->demande, 'created');
    }

    public function soumission(OnlineRequestSubmitted $event): void
    {
        $this->audit->tracer($event->demande, 'submitted');
    }

    public function approbation(OnlineRequestApproved $event): void
    {
        $this->audit->tracer($event->demande, 'approved');
    }

    public function rejet(OnlineRequestRejected $event): void
    {
        $this->audit->tracer($event->demande, 'rejected');
    }

    public function cloture(OnlineRequestCompleted $event): void
    {
        $this->audit->tracer($event->demande, 'completed');
    }

    public function document(OnlineRequestDocumentGenerated $event): void
    {
        $document = $event->document;
        $document->loadMissing('demande');

        $this->audit->tracer($document->demande, 'document_generated', [
            'reference' => $document->reference,
        ]);
    }
}
