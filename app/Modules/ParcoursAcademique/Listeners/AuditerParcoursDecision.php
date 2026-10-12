<?php

namespace App\Modules\ParcoursAcademique\Listeners;

use App\Modules\ParcoursAcademique\Events\ParcoursDecisionEnregistree;
use App\Services\AuditService;

/**
 * Audite toute décision du parcours (type, statut, sans PII).
 */
class AuditerParcoursDecision
{
    public function __construct(private readonly AuditService $audit) {}

    public function handle(ParcoursDecisionEnregistree $event): void
    {
        $this->audit->tracer(
            $event->etudiant,
            'parcours.'.$event->categorie,
            'parcours_'.$event->categorie,
            $event->entiteId,
            null,
            ['statut' => $event->statut]
        );
    }
}
