<?php

namespace App\Listeners;

use App\Events\StudentRegistered;
use App\Notifications\ConfirmationDepotInscription;
use App\Services\NotificationService;
use RuntimeException;
use Throwable;

/**
 * Accusé de réception du dépôt (LOOP 12 : « dépôt reçu ») : l'étudiant
 * sait immédiatement que sa soumission est enregistrée et où suivre son
 * dossier. Fail-soft : l'inscription est déjà commise, une panne de
 * notification ne doit jamais la remettre en cause. Idempotent via la
 * clé de déduplication (un event rejoué ne duplique ni la notification
 * ni l'email de confirmation).
 */
class NotifierDepotRecu
{
    public function handle(StudentRegistered $event): void
    {
        $notification = null;

        try {
            $notification = app(NotificationService::class)->notifierEtudiant(
                $event->etudiant,
                'dossier.depot',
                'Dépôt de dossier reçu',
                'Nous avons bien reçu votre dossier d\'inscription. Il sera examiné par la scolarité de votre faculté ; suivez son avancement ici.',
                route('etudiant.suivi'),
                ['reference' => $event->etudiant->num_inscription],
                0,
                'dossier.depot:'.$event->etudiant->id,
            );
        } catch (Throwable) {
            // Fail-soft : la source de vérité reste l'étudiant en base.
        }

        // Email « dossier reçu » (référence de dossier) : envoyé uniquement
        // lors de la PREMIÈRE notification — la clé de déduplication
        // protège aussi l'email d'un rejeu de l'événement.
        if ($notification?->wasRecentlyCreated !== true) {
            return;
        }

        try {
            $event->etudiant->notify(new ConfirmationDepotInscription(
                $event->etudiant->num_inscription,
            ));
        } catch (Throwable) {
            // Trace neutre (jamais le message brut du transport : il
            // contient l'adresse du destinataire) — l'inscription reste
            // la seule source de vérité.
            report(new RuntimeException('Email de confirmation de dépôt non envoyé (inscription conservée).'));
        }
    }
}
