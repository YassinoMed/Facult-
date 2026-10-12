<?php

namespace App\Listeners;

use App\Events\DossierDecisionPrise;
use App\Services\NotificationService;
use Throwable;

/**
 * Notifie l'étudiant lors d'une décision administrative sur son dossier (LOOP 12) :
 * - Contenu minimal et non sensible (zéro CIN, zéro PII superflue) ;
 * - Idempotence stricte par clé de déduplication (decision:{id}:{statut}) ;
 * - Fail-soft total : la décision métier en base est déjà validée et
 *   ne peut jamais être impactée par une anomalie de notification.
 */
class NotifierDecisionDossier
{
    public function handle(DossierDecisionPrise $event): void
    {
        try {
            $etudiant = $event->etudiant;
            $estValide = $event->statut === 'valide';

            $titre = $estValide
                ? 'Votre inscription universitaire est validée'
                : 'Mise à jour relative à votre dossier d\'inscription';

            $message = $estValide
                ? 'Félicitations, votre dossier a été officiellement approuvé par la scolarité. Vous pouvez dès à présent consulter votre suivi et télécharger votre récépissé.'
                : 'Une décision administrative a été arrêtée sur votre dossier de candidature. Veuillez vous connecter à votre espace sécurisé pour en prendre connaissance.';

            app(NotificationService::class)->notifierEtudiant(
                $etudiant,
                'dossier.decision',
                $titre,
                $message,
                route('etudiant.suivi'),
                [
                    'decision' => $event->statut,
                    'reference' => $etudiant->num_inscription,
                ],
                $estValide ? 1 : 2,
                'decision:'.$etudiant->id.':'.$event->statut
            );
        } catch (Throwable) {
            // Fail-soft : l'état réel en base reste la seule source de vérité
        }
    }
}
