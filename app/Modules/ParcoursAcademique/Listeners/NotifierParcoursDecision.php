<?php

namespace App\Modules\ParcoursAcademique\Listeners;

use App\Modules\ParcoursAcademique\Events\ParcoursDecisionEnregistree;
use App\Services\CommunicationDispatcher;
use Illuminate\Support\Facades\Log;

/**
 * Notifie l'étudiant d'une décision d'aménagement (§27) via le
 * centre (gabarit + préférences + déduplication). Best-effort :
 * jamais bloquant (sans gabarit = no-op tracé).
 */
class NotifierParcoursDecision
{
    public function handle(ParcoursDecisionEnregistree $event): void
    {
        try {
            app(CommunicationDispatcher::class)->dispatcher(
                'parcours.decision',
                $event->etudiant,
                ['categorie' => $event->categorie, 'statut' => $event->statut],
                route('etudiant.dossier.show', absolute: false),
            );
        } catch (\Throwable $e) {
            Log::warning('parcours.notification-echec', [
                'categorie' => $event->categorie, 'entite' => $event->entiteId,
            ]);
        }
    }
}
