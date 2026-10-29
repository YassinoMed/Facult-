<?php

namespace App\Modules\Tasks\Listeners;

use App\Events\InscriptionAcademiqueCreee;
use App\Modules\Tasks\Services\TaskGenerationService;

/**
 * Nouvelle inscription créée ⇒ synchronisation idempotente de la tâche
 * administrative associée dans le Centre de travail (Lot P3).
 * Fail-soft : ne bloque jamais la création de l'inscription.
 */
class CreerTacheInscription
{
    public function __construct(private TaskGenerationService $generation) {}

    public function handle(InscriptionAcademiqueCreee $event): void
    {
        if (! config('modules.tasks.active', true)) {
            return;
        }

        try {
            $inscription = $event->inscription;
            $faculteId = $inscription->etudiant?->faculte_id;
            $this->generation->synchroniserInscriptions($faculteId);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
