<?php

namespace App\Modules\StudentDossiers\Listeners;

use App\Modules\StudentDossiers\Models\StudentDocument;
use App\Modules\StudentDossiers\Services\ReutilisationDocumentsService;
use Illuminate\Support\Facades\Log;

/**
 * LOOP-UNIREGISTRE-DOCUMENTS-REUTILISABLES-01 : toute évolution d'une
 * pièce (upload, validation, rejet, remplacement, expiration, archivage,
 * suppression) recalcule l'état de la DERNIÈRE inscription ouverte.
 *
 * Les inscriptions clôturées ne sont jamais réécrites : leur décision et
 * leur instantané de version restent l'historique de l'année.
 */
class SynchroniserDocumentsAnnuels
{
    public function handle(object $event): void
    {
        if (! config('modules.student-dossiers.active', false)) {
            return;
        }

        try {
            $etudiantId = $this->etudiantId($event);

            if ($etudiantId === null) {
                return;
            }

            app(ReutilisationDocumentsService::class)
                ->synchroniserDerniereInscriptionActive($etudiantId);
        } catch (\Throwable $e) {
            report($e);
            Log::warning('inscription.documents_synchronisation_echec', [
                'evenement' => $event::class,
            ]);
        }
    }

    private function etudiantId(object $event): ?int
    {
        if (isset($event->document) && $event->document instanceof StudentDocument) {
            $etudiantId = $event->document->dossier?->etudiant_id;

            return $etudiantId !== null ? (int) $etudiantId : null;
        }

        if (isset($event->etudiantId)) {
            return (int) $event->etudiantId;
        }

        return null;
    }
}
