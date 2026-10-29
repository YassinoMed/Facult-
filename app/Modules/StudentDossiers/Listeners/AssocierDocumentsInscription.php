<?php

namespace App\Modules\StudentDossiers\Listeners;

use App\Events\InscriptionAcademiqueCreee;
use App\Modules\StudentDossiers\Services\ReutilisationDocumentsService;
use Illuminate\Support\Facades\Log;

/**
 * LOOP-UNIREGISTRE-DOCUMENTS-REUTILISABLES-01 : dès qu'une inscription
 * annuelle est créée, associe automatiquement les pièces permanentes
 * éligibles — sans nouveau téléversement.
 *
 * Fail-soft : l'inscription est déjà écrite ; une anomalie documentaire
 * est journalisée et sera rattrapée à la prochaine synchronisation, elle
 * ne doit jamais bloquer le parcours.
 */
class AssocierDocumentsInscription
{
    public function handle(InscriptionAcademiqueCreee $event): void
    {
        if (! config('modules.student-dossiers.active', false)) {
            return;
        }

        try {
            app(ReutilisationDocumentsService::class)->synchroniser(
                $event->inscription,
                $event->auteurId,
            );
        } catch (\Throwable $e) {
            report($e);
            Log::warning('inscription.documents_association_echec', [
                'inscription_id' => $event->inscription->id,
            ]);
        }
    }
}
