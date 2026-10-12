<?php

namespace App\Modules\StudentDossiers\Jobs;

use App\Modules\StudentDossiers\Models\StudentDossier;
use App\Modules\StudentDossiers\Services\Pdf\StudentDossierPdfService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Génération différée (§14) : dossiers volumineux uniquement — jamais
 * de PDF lourd dans le cycle HTTP. Idempotent : si un PDF frais existe
 * déjà pour l'empreinte, rien n'est régénéré.
 */
class GenerateStudentDossierPdfJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $dossierId,
        public readonly string $empreinte,
        public readonly ?int $generePar,
    ) {}

    public function handle(StudentDossierPdfService $service): void
    {
        $dossier = StudentDossier::find($this->dossierId);

        if ($dossier === null) {
            return;
        }

        $service->genererMaintenant($dossier, $this->generePar, $this->empreinte);
    }
}
