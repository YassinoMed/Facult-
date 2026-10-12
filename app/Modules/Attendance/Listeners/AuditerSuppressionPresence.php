<?php

namespace App\Modules\Attendance\Listeners;

use App\Modules\Attendance\Events\PresenceSupprimee;
use App\Services\AuditService;

/**
 * Audit transverse : toute suppression de relevé laisse une trace
 * `attendance.supprimee` (statut + date, sans PII) — le relevé
 * supprimé reste identifiable par son id technique.
 */
class AuditerSuppressionPresence
{
    public function __construct(private readonly AuditService $audit) {}

    public function handle(PresenceSupprimee $event): void
    {
        $presence = $event->presence;

        $this->audit->tracer(
            $presence->etudiant,
            'attendance.supprimee',
            'presence',
            $presence->id,
            ['statut' => $presence->statut, 'date' => $presence->date->format('Y-m-d')],
        );
    }
}
