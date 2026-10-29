<?php

namespace App\Modules\Schedule\Services;

use App\Models\AppNotification;
use App\Models\Etudiant;
use App\Models\User;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Service de sécurité renforcée, cloisonnement multi-facultés,
 * gestion de la concurrence et centre de notifications d'emploi du temps (Phase 10).
 */
class ScheduleSecurityAuditService
{
    /**
     * Vérifie si un utilisateur a le droit d'accéder ou de modifier un planning
     * en respectant le cloisonnement strict multi-facultés.
     */
    public function verifierAccesPlanning(User $user, Schedule $schedule, string $action = 'view'): bool
    {
        if (! $user->estActif()) {
            return false;
        }

        // Super administrateur : accès transversal illimité
        if ($user->estSuperAdmin()) {
            return true;
        }

        // Administrateur de faculté : périmètre strictement borné à sa faculté
        if ($user->estAdminFaculte()) {
            $facultePlanning = $schedule->groupe?->faculte_id;
            if ($facultePlanning !== null && $user->faculte_id !== $facultePlanning) {
                return false;
            }
        }

        // Action de modification / publication interdite si le user n'a pas les privilèges
        if ($action === 'publish' && ! $user->estSuperAdmin() && ! $user->estAdminFaculte()) {
            return false;
        }

        return true;
    }

    /**
     * Valide l'accès et lève une DomainException explicite en cas de violation.
     *
     * @throws DomainException
     */
    public function autoriserAccesPlanning(User $user, Schedule $schedule, string $action = 'view'): void
    {
        if (! $this->verifierAccesPlanning($user, $schedule, $action)) {
            $faculteNom = $schedule->groupe?->faculte?->nom ?? "ID {$schedule->groupe?->faculte_id}";
            throw new DomainException("Accès non autorisé : vous ne disposez pas des privilèges requis pour administrer le planning de la faculté {$faculteNom}.");
        }
    }

    /**
     * Contrôle si une ressource physique (salle) ou humaine peut être mobilisée
     * par un utilisateur selon les règles de gouvernance inter-facultés.
     */
    public function verifierAccesRessource(User $user, Room|Teacher $ressource): bool
    {
        if (! $user->estActif()) {
            return false;
        }

        if ($user->estSuperAdmin()) {
            return true;
        }

        if ($ressource instanceof Room && $user->estAdminFaculte()) {
            if ($ressource->faculte_id !== null && $ressource->faculte_id !== $user->faculte_id) {
                return false;
            }
        }

        return true;
    }

    /**
     * @throws DomainException
     */
    public function autoriserAccesRessource(User $user, Room|Teacher $ressource): void
    {
        if (! $this->verifierAccesRessource($user, $ressource)) {
            $nomRessource = $ressource instanceof Room ? "la salle '{$ressource->nom}'" : "l'enseignant '{$ressource->nomComplet()}'";
            throw new DomainException("Cloisonnement multi-facultés : impossible d'affecter {$nomRessource} rattaché(e) à une autre faculté.");
        }
    }

    /**
     * Exécute une opération sur un planning sous verrouillage pessimiste
     * et détection de collision concurrente (Optimistic/Pessimistic concurrency).
     *
     * @template T
     *
     * @param  callable(Schedule): T  $operation
     * @return T
     *
     * @throws DomainException
     */
    public function executerModificationSecurisee(
        User $user,
        Schedule $schedule,
        callable $operation,
        ?string $empreinteAttendue = null
    ) {
        $this->autoriserAccesPlanning($user, $schedule, 'edit');

        return DB::transaction(function () use ($schedule, $operation, $empreinteAttendue) {
            /** @var Schedule $planningVerrouille */
            $planningVerrouille = Schedule::where('id', $schedule->id)->lockForUpdate()->first();

            if (! $planningVerrouille) {
                throw new DomainException("Le planning (ID {$schedule->id}) n'existe plus.");
            }

            if ($planningVerrouille->estVerrouille()) {
                throw new DomainException('Impossible de modifier le planning : il est actuellement verrouillé ou archivé.');
            }

            // Détection de modification concurrente si une empreinte (timestamp/hash) est passée
            $empreinteActuelle = $planningVerrouille->updated_at?->toIso8601String();
            if ($empreinteAttendue !== null && $empreinteActuelle !== $empreinteAttendue) {
                throw new DomainException("Modification concurrente rejetée : le planning a été mis à jour par un autre gestionnaire entre-temps (Attendu: {$empreinteAttendue}, Actuel: {$empreinteActuelle}).");
            }

            $resultat = $operation($planningVerrouille);

            $planningVerrouille->touch();

            return $resultat;
        });
    }

    /**
     * Notifie les acteurs concernés (enseignants et étudiants) lors d'une perturbation
     * ou d'un ajustement de créneau sans divulguer de données personnelles sensibles (PII).
     *
     * @return array<int, AppNotification>
     */
    public function notifierChangementCreneau(
        ScheduleSlot $slot,
        string $typeChangement,
        string $motif,
        ?int $ancienTeacherId = null,
        ?int $ancienneSalleId = null
    ): array {
        $notifications = [];
        $matiereNom = $slot->matiere?->nom ?? 'Cours';
        $horaire = "le {$slot->day_of_week} de {$slot->start_time} à {$slot->end_time}";

        $titre = match ($typeChangement) {
            'room_changed' => "Changement de salle : {$matiereNom}",
            'time_changed' => "Changement d'horaire : {$matiereNom}",
            'teacher_changed' => "Remplacement d'enseignant : {$matiereNom}",
            'cancelled' => "Séance annulée : {$matiereNom}",
            default => "Modification de cours : {$matiereNom}",
        };

        $message = "La séance de {$matiereNom} ({$horaire}) a été modifiée : {$motif}.";

        // 1. Notification de l'enseignant actuellement affecté
        if ($slot->teacher_id) {
            $notifications[] = AppNotification::create([
                'teacher_id' => $slot->teacher_id,
                'type' => 'schedule_slot_'.$typeChangement,
                'titre' => $titre,
                'message' => $message,
                'data' => [
                    'schedule_id' => $slot->schedule_id,
                    'slot_id' => $slot->id,
                    'matiere_id' => $slot->matiere_id,
                    'type_changement' => $typeChangement,
                ],
                'priorite' => 'haute',
            ]);
        }

        // 2. Notification de l'ancien enseignant (en cas de remplacement)
        if ($ancienTeacherId && $ancienTeacherId !== $slot->teacher_id) {
            $notifications[] = AppNotification::create([
                'teacher_id' => $ancienTeacherId,
                'type' => 'schedule_slot_desaffecte',
                'titre' => "Fin d'affectation : {$matiereNom}",
                'message' => "Vous avez été libéré(e) de la séance de {$matiereNom} ({$horaire}) : {$motif}.",
                'data' => [
                    'schedule_id' => $slot->schedule_id,
                    'slot_id' => $slot->id,
                    'type_changement' => 'replaced',
                ],
                'priorite' => 'normale',
            ]);
        }

        // 3. Notification des étudiants inscrits dans le groupe
        $groupe = $slot->schedule?->groupe;
        if ($groupe) {
            $etudiants = Etudiant::where('groupe_id', $groupe->id)
                ->limit(100)
                ->get();

            foreach ($etudiants as $etudiant) {
                $notifications[] = AppNotification::create([
                    'etudiant_id' => $etudiant->id,
                    'type' => 'schedule_slot_alert',
                    'titre' => $titre,
                    'message' => $message,
                    'data' => [
                        'schedule_id' => $slot->schedule_id,
                        'slot_id' => $slot->id,
                        'type_changement' => $typeChangement,
                    ],
                    'priorite' => 'haute',
                ]);
            }
        }

        return $notifications;
    }
}
