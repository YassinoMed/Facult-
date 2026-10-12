<?php

namespace App\Listeners;

use App\Events\InscriptionValidee;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Services\AffectationAutomatiqueService;
use Throwable;

/**
 * LOOP affectation automatique : après validation officielle, tente
 * l'affectation via le moteur existant. Ne reproduit aucune règle
 * métier (délègue au service). Ne bloque JAMAIS l'inscription : toute
 * erreur est auditée dans le service, ici simple garde ultime.
 */
class TenterAffectationAutomatique
{
    public function handle(InscriptionValidee $event): void
    {
        try {
            $inscription = InscriptionAcademique::find($event->inscriptionId);

            if ($inscription === null) {
                return;
            }

            $scope = null;

            if ($event->auteurId !== null) {
                $auteur = User::find($event->auteurId);
                $scope = $auteur?->faculteRestreinteId();
            }

            app(AffectationAutomatiqueService::class)->tenter($inscription, $event->auteurId, $scope);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
