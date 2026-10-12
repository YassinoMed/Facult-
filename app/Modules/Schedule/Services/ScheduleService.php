<?php

namespace App\Modules\Schedule\Services;

use App\Modules\Schedule\Events\ScheduleChanged;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleChange;
use App\Modules\Schedule\Models\ScheduleException;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Cycle de vie des plannings (§9) : créneaux, exceptions, annulations,
 * historique. Chaque mutation : conflits → transaction → historique →
 * event → notification → invalidation du cache de version.
 */
class ScheduleService
{
    public function __construct(
        private readonly ScheduleConflictService $conflits,
        private readonly ScheduleNotificationService $notifications,
    ) {}

    /**
     * Vérification sèche avant déplacement (aucune écriture) : mêmes
     * règles que modifierCreneau, créneau lui-même ignoré.
     *
     * @return array<int, string>
     */
    public function conflitsPour(
        ScheduleSlot $slot,
        string $jour,
        string $debut,
        string $fin,
        ?int $salleId,
    ): array {
        $slot->loadMissing('schedule.groupe');

        return $this->conflits->verifier(
            $slot->schedule_id, $jour, $debut, $fin,
            $slot->teacher_id,
            $salleId ?? $slot->room_id,
            $slot->id,
            $slot->schedule->groupe->etudiants()->count(),
        );
    }

    /**
     * @param  array<string, mixed>  $donnees
     * @return array{slot?: ScheduleSlot, erreurs?: array<int, string>}
     */
    public function creerCreneau(Schedule $schedule, array $donnees, ?int $auteur = null, ?string $motif = null): array
    {
        $schedule->loadMissing('groupe');

        /** @var array{slot?: ScheduleSlot, erreurs?: array<int, string>} $resultat */
        $resultat = DB::transaction(function () use ($schedule, $donnees, $auteur, $motif) {
            // Verrous ordonnés + re-vérification du verrouillage sous
            // transaction, puis contrôle des conflits SOUS verrous (une
            // réservation concurrente ne peut plus passer entre la
            // vérification et l'écriture).
            $verrouille = $this->sousVerrous(
                $schedule,
                $donnees['teacher_id'] ?? null,
                $donnees['room_id'] ?? null,
            );

            $erreurs = $this->conflits->verifier(
                $verrouille->id,
                $donnees['day_of_week'],
                $donnees['start_time'],
                $donnees['end_time'],
                $donnees['teacher_id'] ?? null,
                $donnees['room_id'] ?? null,
                null,
                $verrouille->groupe->etudiants()->count(),
            );

            if ($erreurs !== []) {
                return ['erreurs' => $erreurs];
            }

            /** @var ScheduleSlot $slot */
            $slot = $verrouille->slots()->create($donnees);

            $this->historiser($slot, 'created', null, $slot->only([
                'matiere_id', 'teacher_id', 'room_id', 'day_of_week',
                'start_time', 'end_time', 'type', 'status',
            ]), $auteur, $motif);

            return ['slot' => $slot];
        });

        if (isset($resultat['slot'])) {
            $this->publier($resultat['slot'], 'created', "Nouveau créneau : {$resultat['slot']->matiere->nom}.");
        }

        return $resultat;
    }

    /**
     * @param  array<string, mixed>  $donnees
     * @return array{slot?: ScheduleSlot, erreurs?: array<int, string>}
     */
    public function modifierCreneau(ScheduleSlot $slot, array $donnees, ?int $auteur = null, ?string $motif = null): array
    {
        $jour = $donnees['day_of_week'] ?? $slot->day_of_week;
        $debut = $donnees['start_time'] ?? $slot->start_time;
        $fin = $donnees['end_time'] ?? $slot->end_time;
        $teacherId = array_key_exists('teacher_id', $donnees) ? $donnees['teacher_id'] : $slot->teacher_id;
        $roomId = array_key_exists('room_id', $donnees) ? $donnees['room_id'] : $slot->room_id;

        /** @var array{slot?: ScheduleSlot, erreurs?: array<int, string>} $resultat */
        $resultat = DB::transaction(function () use ($slot, $donnees, $auteur, $motif, $jour, $debut, $fin, $teacherId, $roomId) {
            // Re-vérification du verrouillage + conflits sous verrous ordonnés.
            $verrouille = $this->sousVerrous($slot->schedule, $teacherId, $roomId);

            $erreurs = $this->conflits->verifier(
                $slot->schedule_id, $jour, $debut, $fin, $teacherId, $roomId,
                $slot->id,
                $verrouille->groupe->etudiants()->count(),
            );

            if ($erreurs !== []) {
                return ['erreurs' => $erreurs];
            }

            // Toute modification d'un créneau planifié le marque modifié.
            if (! array_key_exists('status', $donnees) && $slot->status === 'scheduled') {
                $donnees['status'] = 'modified';
            }

            $avant = $slot->only(['teacher_id', 'room_id', 'day_of_week', 'start_time', 'end_time', 'type', 'status', 'matiere_id', 'notes']);

            $slot->update($donnees);

            $this->historiser($slot->fresh(), $this->qualifier($avant, $slot->fresh()->toArray()), $avant, $donnees, $auteur, $motif);

            return ['slot' => $slot->fresh()];
        });

        if (isset($resultat['slot'])) {
            $slot = $resultat['slot'];
            $this->publier($slot, 'updated', "Créneau modifié : {$slot->matiere->nom}.");
        }

        return $resultat;
    }

    public function annulerCreneau(ScheduleSlot $slot, ?int $auteur = null, ?string $motif = null): ScheduleSlot
    {
        $slot = DB::transaction(function () use ($slot, $auteur, $motif) {
            $this->sousVerrous($slot->schedule);

            $avant = ['status' => $slot->status];
            $slot->update(['status' => 'cancelled']);

            $this->historiser($slot, 'cancelled', $avant, ['status' => 'cancelled'], $auteur, $motif);

            return $slot;
        });

        // Post-commit (SCHEDULE-10) : les canaux externes partent après la
        // validation définitive — un rollback ne produit aucune notification.
        $this->publier($slot, 'cancelled', "Cours annulé : {$slot->matiere->nom}.");

        return $slot;
    }

    public function restaurerCreneau(ScheduleSlot $slot, ?int $auteur = null, ?string $motif = null): ScheduleSlot
    {
        $slot = DB::transaction(function () use ($slot, $auteur, $motif) {
            $this->sousVerrous($slot->schedule);

            $slot->update(['status' => 'scheduled']);

            $this->historiser($slot, 'restored', ['status' => 'cancelled'], ['status' => 'scheduled'], $auteur, $motif);

            return $slot;
        });

        $this->publier($slot, 'restored', "Cours restauré : {$slot->matiere->nom}.");

        return $slot;
    }

    public function supprimerCreneau(ScheduleSlot $slot, ?int $auteur = null, ?string $motif = null): void
    {
        // Message capturé AVANT le delete (le modèle supprimé reste en
        // mémoire) — l'émission elle-même est post-commit (SCHEDULE-10).
        $message = 'Créneau supprimé : '.($slot->matiere?->nom ?? '—').'.';

        DB::transaction(function () use ($slot, $auteur, $motif) {
            $this->sousVerrous($slot->schedule);

            // Garde métier (pattern E1) : les présences enseignantes sont en
            // `restrictOnDelete` — refus explicite, historique conservé, jamais
            // de détachement automatique ni d'erreur 500.
            if ($slot->presences()->exists()) {
                throw new DomainException('Suppression impossible : des présences sont liées à ce créneau.');
            }
            $this->historiser($slot, 'deleted', $slot->toArray(), null, $auteur, $motif);

            try {
                $slot->delete();
            } catch (QueryException $e) {
                // Filet anti-TOCTOU : une présence créée entre la garde et
                // l'écriture violerait la FK restrict — refus métier, pas 500.
                throw new DomainException('Suppression impossible : des liens existent encore sur ce créneau.');
            }
        });

        $this->publier($slot, 'deleted', $message);
    }

    /**
     * @param  array<string, mixed>  $donnees
     */
    public function creerException(ScheduleSlot $slot, array $donnees, ?int $auteur = null): ScheduleException
    {
        $exception = DB::transaction(function () use ($slot, $donnees, $auteur) {
            $this->sousVerrous($slot->schedule, $donnees['new_teacher_id'] ?? null, $donnees['new_room_id'] ?? null);

            /** @var ScheduleException $exception */
            $exception = $slot->exceptions()->create($donnees);

            $this->historiser($slot, 'exception', null, $donnees, $auteur, $donnees['reason'] ?? null);

            return $exception;
        });

        $this->publier($slot, 'exception', "Exception le {$exception->exception_date->format('d/m/Y')} : {$slot->matiere->nom}.");

        return $exception;
    }

    private function verifierModifiable(Schedule $schedule): void
    {
        if ($schedule->estVerrouille()) {
            throw new DomainException('Planning verrouillé : toute modification exige un retour au brouillon.');
        }
    }

    /**
     * Verrous pessimistes ordonnés — planning → enseignant → salle (ordre
     * constant, anti-deadlock croisé) — puis re-vérification du verrou
     * métier SUR LA LIGNE VERROUILLÉE (SCHEDULE-03/12 : jamais sur une
     * instance potentiellement périmée ; convention inscriptions/
     * affectations). À appeller au début de la transaction.
     *
     * @param  int|null  $teacherId  Verrou enseignant si la mutation l'implique (conflit global).
     * @param  int|null  $roomId  Verrou salle si la mutation l'implique (conflit global).
     */
    private function sousVerrous(Schedule $schedule, ?int $teacherId = null, ?int $roomId = null): Schedule
    {
        /** @var Schedule $verrouille */
        $verrouille = Schedule::whereKey($schedule->id)->lockForUpdate()->firstOrFail();

        if ($teacherId !== null) {
            Teacher::whereKey($teacherId)->lockForUpdate()->first();
        }

        if ($roomId !== null) {
            Room::whereKey($roomId)->lockForUpdate()->first();
        }

        $this->verifierModifiable($verrouille);

        return $verrouille;
    }

    private function qualifier(array $avant, array $apres): string
    {
        if (($avant['room_id'] ?? null) !== ($apres['room_id'] ?? null)) {
            return 'room_changed';
        }
        if (($avant['teacher_id'] ?? null) !== ($apres['teacher_id'] ?? null)) {
            return 'teacher_changed';
        }
        if (($avant['day_of_week'] ?? null) !== ($apres['day_of_week'] ?? null)
            || ($avant['start_time'] ?? null) !== ($apres['start_time'] ?? null)
            || ($avant['end_time'] ?? null) !== ($apres['end_time'] ?? null)) {
            return 'time_changed';
        }

        return 'updated';
    }

    /**
     * @param  array<string, mixed>|null  $avant
     * @param  array<string, mixed>|null  $apres
     */
    private function historiser(ScheduleSlot $slot, string $type, ?array $avant, ?array $apres, ?int $auteur, ?string $motif): void
    {
        ScheduleChange::create([
            'schedule_slot_id' => $slot->id,
            'changed_by' => $auteur,
            'change_type' => $type,
            'old_values' => $avant,
            'new_values' => $apres,
            'reason' => $motif,
        ]);
    }

    private function publier(ScheduleSlot $slot, string $type, string $message): void
    {
        ScheduleChanged::dispatch($slot->schedule_id, $slot->id, $type);
        $this->notifications->notifier($slot, $message);
    }

    /**
     * Contrôle pré-publication (§12.19) : conflits + créneaux sans
     * enseignant. Publication bloquée si anomalies.
     *
     * @return array{conflits: array<int, string>, sans_enseignant: int, creneaux: int}
     */
    public function controlePublication(Schedule $schedule): array
    {
        $conflits = [];
        $sansEnseignant = 0;
        $creneaux = 0;

        foreach ($schedule->slots()->where('status', '!=', 'cancelled')->cursor() as $slot) {
            $creneaux++;

            if ($slot->teacher_id === null) {
                $sansEnseignant++;
            }

            foreach ($this->conflitsPour($slot, $slot->day_of_week, (string) $slot->start_time, (string) $slot->end_time, $slot->room_id) as $conflit) {
                $conflits[] = "{$slot->matiere->code} {$slot->day_of_week} ".substr((string) $slot->start_time, 0, 5).' : '.(is_array($conflit) ? ($conflit['message'] ?? json_encode($conflit)) : $conflit);
            }
        }

        return ['conflits' => array_values(array_unique($conflits)), 'sans_enseignant' => $sansEnseignant, 'creneaux' => $creneaux];
    }

    /**
     * Transitions intermédiaires (brouillon ↔ relecture ↔ valide) :
     * matrice serveur, sans effet de bord.
     */
    public function transiterPlanning(Schedule $schedule, string $cible): Schedule
    {
        $schedule = DB::transaction(function () use ($schedule, $cible) {
            $schedule = Schedule::whereKey($schedule->id)->lockForUpdate()->firstOrFail();

            if (! $schedule->peutTransiterVers($cible)) {
                throw new DomainException("Transition {$schedule->statut} → {$cible} interdite.");
            }

            $schedule->update(['statut' => $cible]);

            return $schedule->fresh();
        });

        // Post-commit (SCHEDULE-10) : un rollback ne produit aucun événement.
        ScheduleChanged::dispatch($schedule->id, 0, "statut:{$cible}");

        return $schedule;
    }

    public function publierPlanning(Schedule $schedule, ?int $auteur = null): Schedule
    {
        $schedule = DB::transaction(function () use ($schedule) {
            $schedule = Schedule::whereKey($schedule->id)->lockForUpdate()->firstOrFail();

            if (! $schedule->peutTransiterVers('publie')) {
                throw new DomainException("Publication interdite depuis « {$schedule->statut} ».");
            }

            $controle = $this->controlePublication($schedule);

            if ($controle['conflits'] !== []) {
                throw new DomainException(count($controle['conflits']).' conflit(s) bloquent la publication.');
            }

            $schedule->update(['statut' => 'publie', 'publie_a' => now(), 'actif' => true]);

            return $schedule->fresh();
        });

        ScheduleChanged::dispatch($schedule->id, 0, 'publie');

        return $schedule;
    }

    public function verrouillerPlanning(Schedule $schedule): Schedule
    {
        $schedule = DB::transaction(function () use ($schedule) {
            $schedule = Schedule::whereKey($schedule->id)->lockForUpdate()->firstOrFail();

            if (! $schedule->peutTransiterVers('verrouille')) {
                throw new DomainException("Verrouillage interdit depuis « {$schedule->statut} ».");
            }

            $schedule->update(['statut' => 'verrouille']);

            return $schedule->fresh();
        });

        ScheduleChanged::dispatch($schedule->id, 0, 'verrouille');

        return $schedule;
    }

    /**
     * Duplication (§12.13) : créneaux vers un autre groupe/semestre/
     * année, avec prévisualisation du nombre avant confirmation.
     *
     * @param  array{groupe_id: int, annee_universitaire_id: int, semestre: int}  $cible
     */
    public function previsualiserDuplication(Schedule $schedule): int
    {
        return $schedule->slots()->where('status', '!=', 'cancelled')->count();
    }

    /**
     * @param  array{groupe_id: int, annee_universitaire_id: int, semestre: int}  $cible
     */
    public function dupliquer(Schedule $schedule, array $cible, ?int $auteur = null): Schedule
    {
        $copie = DB::transaction(function () use ($schedule, $cible) {
            /** @var Schedule $copie */
            $copie = Schedule::create([
                'annee_universitaire_id' => $cible['annee_universitaire_id'],
                'semestre' => $cible['semestre'],
                'groupe_id' => $cible['groupe_id'],
                'nom' => $schedule->nom.' (copie)',
                'starts_at' => $schedule->starts_at,
                'ends_at' => $schedule->ends_at,
                'actif' => false,
                'statut' => 'brouillon',
            ]);

            foreach ($schedule->slots()->where('status', '!=', 'cancelled')->cursor() as $slot) {
                $copie->slots()->create([
                    'matiere_id' => $slot->matiere_id,
                    'teacher_id' => $slot->teacher_id,
                    'room_id' => null,
                    'day_of_week' => $slot->day_of_week,
                    'start_time' => $slot->start_time,
                    'end_time' => $slot->end_time,
                    'type' => $slot->type,
                    'status' => 'scheduled',
                    'notes' => 'Dupliqué depuis planning #'.$schedule->id,
                ]);
            }

            return $copie;
        });

        ScheduleChanged::dispatch($copie->id, 0, 'duplique');

        return $copie;
    }
}
