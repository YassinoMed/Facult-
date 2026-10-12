<?php

namespace App\Modules\StudentArchive\Listeners;

use App\Models\Etudiant;
use App\Models\HistoriqueAcademique;
use App\Modules\StudentArchive\Events\ArchiveConsultee;
use App\Services\AuditService;

/**
 * Audit des consultations archive (§28) : acteur, étudiant, onglet,
 * IP/UA/request — sans PII métier (AuditService).
 */
class AuditerArchive
{
    public function __construct(private readonly AuditService $audit) {}

    public function handle(ArchiveConsultee $event): void
    {
        $etudiant = Etudiant::find($event->etudiantId);

        if ($etudiant === null) {
            return;
        }

        $this->audit->tracer(
            $etudiant,
            HistoriqueAcademique::ACTION_ARCHIVE_VIEW,
            'archive',
            $etudiant->id,
            null,
            ['onglet' => $event->onglet],
        );
    }
}
