<?php

namespace App\Listeners;

use App\Events\DossierDecisionPrise;
use App\Services\AffectationAutomatiqueService;
use Throwable;

/**
 * Déclenche la tentative d'affectation automatique lorsqu'un dossier
 * d'inscription est validé par la scolarité (LOOP 14) :
 * - Uniquement lorsque le statut est 'valide' ;
 * - Transactionnellement découplé (fail-soft) : ne bloque jamais la décision ;
 * - Idempotent : si déjà affecté, aucune opération ;
 * - Respect strict des règles de compatibilité (faculté, niveau, capacité).
 */
class AffecterApresDecisionDossier
{
    public function handle(DossierDecisionPrise $event): void
    {
        if ($event->statut !== 'valide') {
            return;
        }

        try {
            app(AffectationAutomatiqueService::class)->tenterPourEtudiant(
                $event->etudiant,
                $event->auteurId
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
