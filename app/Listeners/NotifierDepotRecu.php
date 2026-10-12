<?php

namespace App\Listeners;

use App\Events\StudentRegistered;
use App\Services\NotificationService;
use Throwable;

/**
 * Accusé de réception du dépôt (LOOP 12 : « dépôt reçu ») : l'étudiant
 * sait immédiatement que sa soumission est enregistrée et où suivre son
 * dossier. Fail-soft : l'inscription est déjà commise, une panne de
 * notification ne doit jamais la remettre en cause. Idempotent via la
 * clé de déduplication (un event rejoué ne duplique pas).
 */
class NotifierDepotRecu
{
    public function handle(StudentRegistered $event): void
    {
        try {
            app(NotificationService::class)->notifierEtudiant(
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
    }
}
