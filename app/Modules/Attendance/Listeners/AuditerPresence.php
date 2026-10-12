<?php

namespace App\Modules\Attendance\Listeners;

use App\Modules\Attendance\Events\PresenceEnregistree;
use App\Services\AuditService;

/**
 * Audit transverse (§23) : toute présence enregistrée laisse une trace
 * `attendance.enregistree` dans l'historique — sans mot de passe ni
 * secret, comme le reste du journal.
 */
class AuditerPresence
{
    public function __construct(private readonly AuditService $audit) {}

    public function handle(PresenceEnregistree $event): void
    {
        $presence = $event->presence;

        $this->audit->tracer(
            $presence->etudiant,
            'attendance.enregistree',
            'presence',
            $presence->id,
            null,
            ['statut' => $presence->statut, 'date' => $presence->date->format('Y-m-d')],
        );
    }
}
