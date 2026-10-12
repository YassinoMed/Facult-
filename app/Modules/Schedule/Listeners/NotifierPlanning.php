<?php

namespace App\Modules\Schedule\Listeners;

use App\Modules\Schedule\Events\ScheduleChanged;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notifications planning (§5/§15) : chaque changement notifie les
 * étudiants du groupe — titre/message humains, lien vers le planning,
 * aucune PII superflue.
 */
class NotifierPlanning
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    public function handle(ScheduleChanged $event): void
    {
        // Fail-soft (convention écouteurs projet) : une panne du canal de
        // notification ne doit JAMAIS casser l'opération planning qui vient
        // d'être validée — journal sans PII, puis sortie silencieuse.
        try {
            $this->notifier($event);
        } catch (Throwable $e) {
            Log::warning('schedule.notification_echec', [
                'slot_id' => $event->slotId,
                'type' => $event->typeChangement,
                'exception' => $e::class,
            ]);
        }
    }

    private function notifier(ScheduleChanged $event): void
    {
        $slot = ScheduleSlot::with(['matiere', 'teacher', 'schedule.groupe.etudiants'])
            ->find($event->slotId);

        if ($slot === null || in_array($event->typeChangement, ['notifie'], true)) {
            return;
        }

        [$titre, $message] = match ($event->typeChangement) {
            'created' => ['Nouveau cours', "Nouveau créneau « {$slot->matiere->nom} » à votre emploi du temps."],
            'cancelled' => ['Cours annulé', "Le cours « {$slot->matiere->nom} » est annulé. Consultez votre planning."],
            'restored' => ['Cours restauré', "Le cours « {$slot->matiere->nom} » est de nouveau planifié."],
            'deleted' => ['Créneau supprimé', "Un créneau « {$slot->matiere->nom} » a été retiré de votre planning."],
            'exception' => ['Emploi du temps modifié', "Changement ponctuel sur « {$slot->matiere->nom} » — vérifiez votre planning."],
            'remplace', 'importe' => ['Nouvel emploi du temps', 'Votre emploi du temps a été mis à jour. Consultez-le.'],
            default => ['Emploi du temps modifié', "Le cours « {$slot->matiere->nom} » a été modifié. Vérifiez votre planning."],
        };

        foreach ($slot->schedule->groupe->etudiants as $etudiant) {
            $this->notifications->notifierEtudiant(
                $etudiant,
                'schedule.'.$event->typeChangement,
                $titre,
                $message,
                route('etudiant.emploi-du-temps.semaine'),
                ['schedule_id' => $event->scheduleId, 'slot_id' => $event->slotId],
            );
        }

        // Canal enseignant (espace enseignant) : l'enseignant du créneau
        // est averti sur son propre centre, lien vers son portail.
        if ($slot->teacher !== null) {
            $this->notifications->notifierEnseignant(
                $slot->teacher,
                'schedule.'.$event->typeChangement,
                $titre,
                $message,
                route('enseignant.emploi-du-temps.semaine'),
                ['schedule_id' => $event->scheduleId, 'slot_id' => $event->slotId],
            );
        }
    }
}
