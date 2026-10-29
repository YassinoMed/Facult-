<?php

namespace App\Listeners;

use App\Events\DossierDecisionPrise;
use App\Notifications\DecisionDossierInscription;
use App\Services\NotificationService;
use RuntimeException;
use Throwable;

/**
 * Notifie l'étudiant lors d'une décision administrative sur son dossier (LOOP 12) :
 * - Contenu minimal et non sensible (zéro CIN, zéro PII superflue) ;
 * - Idempotence stricte par clé de déduplication (decision:{id}:{statut}) ;
 * - Fail-soft total : la décision métier en base est déjà validée et
 *   ne peut jamais être impactée par une anomalie de notification.
 *
 * Deux canaux : notification in-app (centre de notifications) et email
 * de décision (validation / rejet) envoyé de façon synchrone — la file
 * `notifications` n'étant pas servie en permanence, la décision doit
 * atteindre l'étudiant sans dépendre d'un worker. L'email suit la même
 * clé de déduplication : un rejeu de la décision ne le double pas.
 */
class NotifierDecisionDossier
{
    public function handle(DossierDecisionPrise $event): void
    {
        $notification = null;

        try {
            $etudiant = $event->etudiant;
            $estValide = $event->statut === 'valide';

            $titre = $estValide
                ? 'Votre inscription universitaire est validée'
                : 'Mise à jour relative à votre dossier d\'inscription';

            $message = $estValide
                ? 'Félicitations, votre dossier a été officiellement approuvé par la scolarité. Vous pouvez dès à présent consulter votre suivi et télécharger votre récépissé.'
                : 'Une décision administrative a été arrêtée sur votre dossier de candidature. Veuillez vous connecter à votre espace sécurisé pour en prendre connaissance.';

            $notification = app(NotificationService::class)->notifierEtudiant(
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

        // Email de décision : uniquement lors de la PREMIÈRE notification
        // pour cette décision (rejeu protégé par la clé de déduplication).
        if ($notification?->wasRecentlyCreated !== true) {
            return;
        }

        try {
            $event->etudiant->notify(new DecisionDossierInscription(
                $event->etudiant->num_inscription,
                $event->statut,
                $event->motif,
            ));
        } catch (Throwable) {
            // Trace neutre (jamais le message brut du transport : il
            // contient l'adresse du destinataire) — la décision reste la
            // seule source de vérité.
            report(new RuntimeException('Email de décision de dossier non envoyé (décision conservée).'));
        }
    }
}
