<?php

namespace App\Modules\Schedule\Services;

use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\ScheduleAvailability;
use App\Modules\Schedule\Models\ScheduleChange;
use App\Modules\Schedule\Models\ScheduleSlot;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Service gérant les opérations interactives sur la grille d'emploi du temps (Drag-and-drop) :
 * prévisualisation temps réel de conflits, déplacements sécurisés, redimensionnements
 * et échanges atomiques de créneaux (swaps).
 */
class ScheduleInteractiveService
{
    public function __construct(
        protected ScheduleConflictService $conflictService,
        protected ScheduleConstraintChecker $constraintChecker
    ) {}

    /**
     * Prévisualise un déplacement de créneau et retourne la faisabilité,
     * les conflits durs bloquants et les alertes d'optimisation/qualité.
     *
     * @return array{
     *     faisable: bool,
     *     conflits_durs: array<int, string>,
     *     alertes_qualite: array<int, string>,
     *     impact_score: int,
     *     recommandation: string
     * }
     */
    public function previsualiserDeplacement(
        ScheduleSlot $slot,
        string $nouveauJour,
        string $nouvelleHeureDebut,
        string $nouvelleHeureFin,
        ?int $nouvelleSalleId = null
    ): array {
        $conflitsDurs = [];
        $alertesQualite = [];
        $impactScore = 0;

        $nouvelleSalleId = $nouvelleSalleId ?? $slot->room_id;
        $schedule = $slot->schedule;

        // Normalisation des heures (HH:MM:SS)
        $debutNorm = strlen($nouvelleHeureDebut) === 5 ? $nouvelleHeureDebut.':00' : $nouvelleHeureDebut;
        $finNorm = strlen($nouvelleHeureFin) === 5 ? $nouvelleHeureFin.':00' : $nouvelleHeureFin;

        if ($debutNorm >= $finNorm) {
            $conflitsDurs[] = "L'heure de début ({$nouvelleHeureDebut}) doit être antérieure à l'heure de fin ({$nouvelleHeureFin}).";

            return [
                'faisable' => false,
                'conflits_durs' => $conflitsDurs,
                'alertes_qualite' => $alertesQualite,
                'impact_score' => -100,
                'recommandation' => 'bloque',
            ];
        }

        // 1. Vérification de la disponibilité et conformité de la salle
        if ($nouvelleSalleId !== null) {
            /** @var Room|null $salle */
            $salle = Room::find($nouvelleSalleId);
            if (! $salle) {
                $conflitsDurs[] = "La salle sélectionnée (ID {$nouvelleSalleId}) n'existe pas.";
            } elseif (! $salle->actif) {
                $conflitsDurs[] = "La salle '{$salle->nom}' est actuellement désactivée ou fermée.";
            } else {
                // Plage d'ouverture de la salle
                if ($salle->heure_ouverture && $debutNorm < $salle->heure_ouverture) {
                    $conflitsDurs[] = "La salle '{$salle->nom}' n'ouvre qu'à {$salle->heure_ouverture} (cours prévu à {$debutNorm}).";
                }
                if ($salle->heure_fermeture && $finNorm > $salle->heure_fermeture) {
                    $conflitsDurs[] = "La salle '{$salle->nom}' ferme à {$salle->heure_fermeture} (fin du cours à {$finNorm}).";
                }

                // Occupation de la salle par un autre créneau
                $salleOccupee = ScheduleSlot::where('room_id', $nouvelleSalleId)
                    ->where('day_of_week', $nouveauJour)
                    ->where('id', '!=', $slot->id)
                    ->where('status', '!=', 'cancelled')
                    ->where(function ($q) use ($debutNorm, $finNorm) {
                        $q->where('start_time', '<', $finNorm)
                            ->where('end_time', '>', $debutNorm);
                    })
                    ->first();

                if ($salleOccupee && $slot->conflitSemainesAvec($salleOccupee->semaine_parite, $salleOccupee->semaines_specifiques)) {
                    $conflitsDurs[] = "La salle '{$salle->nom}' est déjà occupée sur ce créneau ({$salleOccupee->start_time} - {$salleOccupee->end_time}).";
                }

                // Équipements requis
                $manquants = $slot->conflitEquipementsAvec($salle);
                if (! empty($manquants)) {
                    $conflitsDurs[] = "La salle '{$salle->nom}' ne dispose pas des équipements requis : ".implode(', ', $manquants).'.';
                }

                // Capacité d'accueil vs effectif du groupe
                $groupe = $schedule?->groupe;
                $effectif = $groupe?->effectif ?? $groupe?->capacite_max ?? 0;
                if ($effectif > 0 && $salle->capacite > 0 && $salle->capacite < $effectif) {
                    $alertesQualite[] = "Capacité de la salle '{$salle->nom}' ({$salle->capacite} places) insuffisante pour l'effectif estimé ({$effectif} étudiants).";
                    $impactScore -= 15;
                }
            }
        }

        // 2. Vérification des enseignants (principal + co-enseignants)
        $enseignantsIds = $slot->tousEnseignantsIds();
        foreach ($enseignantsIds as $teacherId) {
            // Indisponibilité déclarée
            $indispo = ScheduleAvailability::where('teacher_id', $teacherId)
                ->where('day_of_week', $nouveauJour)
                ->where('type', 'indisponible')
                ->where(function ($q) use ($debutNorm, $finNorm) {
                    $q->where('start_time', '<', $finNorm)
                        ->where('end_time', '>', $debutNorm);
                })
                ->first();

            if ($indispo) {
                $conflitsDurs[] = "L'enseignant (ID {$teacherId}) est indisponible le {$nouveauJour} de {$indispo->start_time} à {$indispo->end_time}.".($indispo->reason ? " ({$indispo->reason})" : '');
            }

            // Chevauchement avec un autre cours de l'enseignant
            $profOccupe = ScheduleSlot::where(function ($q) use ($teacherId) {
                $q->where('teacher_id', $teacherId)
                    ->orWhereHas('teachersPivot', fn ($p) => $p->where('teacher_id', $teacherId));
            })
                ->where('day_of_week', $nouveauJour)
                ->where('id', '!=', $slot->id)
                ->where('status', '!=', 'cancelled')
                ->where(function ($q) use ($debutNorm, $finNorm) {
                    $q->where('start_time', '<', $finNorm)
                        ->where('end_time', '>', $debutNorm);
                })
                ->first();

            if ($profOccupe && $slot->conflitSemainesAvec($profOccupe->semaine_parite, $profOccupe->semaines_specifiques)) {
                $conflitsDurs[] = "L'enseignant (ID {$teacherId}) a déjà un cours sur ce créneau ({$profOccupe->start_time} - {$profOccupe->end_time}).";
            }

            // Transit inter-bâtiments : cours adjacent de l'enseignant
            if ($nouvelleSalleId && isset($salle) && $salle->batiment) {
                $coursProche = ScheduleSlot::with('room')
                    ->where(function ($q) use ($teacherId) {
                        $q->where('teacher_id', $teacherId)
                            ->orWhereHas('teachersPivot', fn ($p) => $p->where('teacher_id', $teacherId));
                    })
                    ->where('day_of_week', $nouveauJour)
                    ->where('id', '!=', $slot->id)
                    ->where('status', '!=', 'cancelled')
                    ->where(function ($q) use ($debutNorm, $finNorm) {
                        $q->where('end_time', $debutNorm)
                            ->orWhere('start_time', $finNorm);
                    })
                    ->first();

                if ($coursProche && $coursProche->room && $coursProche->room->batiment && $coursProche->room->batiment !== $salle->batiment) {
                    $alertesQualite[] = "L'enseignant doit changer de bâtiment ({$coursProche->room->batiment} vers {$salle->batiment}) sans battement suffisant.";
                    $impactScore -= 10;
                }
            }
        }

        // 3. Vérification du groupe d'étudiants
        if ($schedule && $schedule->groupe_id) {
            $groupeOccupe = ScheduleSlot::whereHas('schedule', function ($q) use ($schedule) {
                $q->where('groupe_id', $schedule->groupe_id)
                    ->where('annee_universitaire_id', $schedule->annee_universitaire_id)
                    ->where('semestre', $schedule->semestre);
            })
                ->where('day_of_week', $nouveauJour)
                ->where('id', '!=', $slot->id)
                ->where('status', '!=', 'cancelled')
                ->where(function ($q) use ($debutNorm, $finNorm) {
                    $q->where('start_time', '<', $finNorm)
                        ->where('end_time', '>', $debutNorm);
                })
                ->first();

            if ($groupeOccupe && $slot->conflitSemainesAvec($groupeOccupe->semaine_parite, $groupeOccupe->semaines_specifiques)) {
                $conflitsDurs[] = "Le groupe d'étudiants a déjà un cours sur ce créneau ({$groupeOccupe->start_time} - {$groupeOccupe->end_time}).";
            }
        }

        // 4. Alertes de qualité pédagogique
        // Pause déjeuner (12h30 - 13h30 par défaut)
        if ($debutNorm < '13:30:00' && $finNorm > '12:30:00') {
            $alertesQualite[] = 'Ce créneau empiète sur la pause déjeuner méridienne (12h30 - 13h30).';
            $impactScore -= 5;
        }

        $faisable = empty($conflitsDurs);
        $recommandation = $faisable ? (empty($alertesQualite) ? 'optimal' : 'acceptable_avec_reserves') : 'bloque';

        return [
            'faisable' => $faisable,
            'conflits_durs' => $conflitsDurs,
            'alertes_qualite' => $alertesQualite,
            'impact_score' => $impactScore,
            'recommandation' => $recommandation,
        ];
    }

    /**
     * Déplace un créneau de manière sécurisée sous transaction,
     * avec contrôle des conflits et historisation complète.
     *
     * @throws DomainException
     */
    public function deplacerCreneau(
        ScheduleSlot $slot,
        string $nouveauJour,
        string $nouvelleHeureDebut,
        string $nouvelleHeureFin,
        ?int $nouvelleSalleId = null,
        ?int $userId = null,
        ?string $motif = null,
        bool $forcerSiSouple = true
    ): ScheduleSlot {
        $schedule = $slot->schedule;

        if ($schedule && $schedule->estVerrouille()) {
            throw new DomainException("Impossible de modifier un planning verrouillé ou archivé (Statut: {$schedule->statut}).");
        }

        if ($schedule && $schedule->statut === 'publie' && empty($motif)) {
            throw new DomainException("La modification d'un planning publié exige un motif explicite de modification.");
        }

        $previsu = $this->previsualiserDeplacement(
            $slot,
            $nouveauJour,
            $nouvelleHeureDebut,
            $nouvelleHeureFin,
            $nouvelleSalleId
        );

        if (! $previsu['faisable']) {
            throw new DomainException('Déplacement impossible en raison de conflits durs : '.implode(' | ', $previsu['conflits_durs']));
        }

        return DB::transaction(function () use ($slot, $nouveauJour, $nouvelleHeureDebut, $nouvelleHeureFin, $nouvelleSalleId, $userId, $motif) {
            $oldValues = [
                'day_of_week' => $slot->day_of_week,
                'start_time' => $slot->start_time,
                'end_time' => $slot->end_time,
                'room_id' => $slot->room_id,
            ];

            $salleCible = $nouvelleSalleId ?? $slot->room_id;
            $debutNorm = strlen($nouvelleHeureDebut) === 5 ? $nouvelleHeureDebut.':00' : $nouvelleHeureDebut;
            $finNorm = strlen($nouvelleHeureFin) === 5 ? $nouvelleHeureFin.':00' : $nouvelleHeureFin;

            $changementHoraire = ($slot->day_of_week !== $nouveauJour || $slot->start_time !== $debutNorm || $slot->end_time !== $finNorm);
            $changementSalle = ($slot->room_id !== $salleCible);

            $changeType = 'updated';
            if ($changementHoraire) {
                $changeType = 'time_changed';
            } elseif ($changementSalle) {
                $changeType = 'room_changed';
            }

            $slot->update([
                'day_of_week' => $nouveauJour,
                'start_time' => $debutNorm,
                'end_time' => $finNorm,
                'room_id' => $salleCible,
            ]);

            $newValues = [
                'day_of_week' => $nouveauJour,
                'start_time' => $debutNorm,
                'end_time' => $finNorm,
                'room_id' => $salleCible,
            ];

            ScheduleChange::create([
                'schedule_slot_id' => $slot->id,
                'changed_by' => $userId,
                'change_type' => $changeType,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'reason' => $motif ?? 'Déplacement interactif (drag-and-drop)',
            ]);

            return $slot->fresh(['room', 'teacher', 'matiere', 'changes']);
        });
    }

    /**
     * Prévisualise l'échange (swap) de deux créneaux.
     *
     * @return array{
     *     faisable: bool,
     *     conflits_a: array<int, string>,
     *     conflits_b: array<int, string>,
     *     alertes: array<int, string>
     * }
     */
    public function previsualiserEchange(ScheduleSlot $slotA, ScheduleSlot $slotB): array
    {
        $previsuA = $this->previsualiserDeplacement(
            $slotA,
            $slotB->day_of_week,
            $slotB->start_time,
            $slotB->end_time,
            $slotB->room_id
        );

        $previsuB = $this->previsualiserDeplacement(
            $slotB,
            $slotA->day_of_week,
            $slotA->start_time,
            $slotA->end_time,
            $slotA->room_id
        );

        $conflitsA = array_filter($previsuA['conflits_durs'], function ($msg) use ($slotB) {
            return ! str_contains($msg, "ID {$slotB->id}") && ! str_contains($msg, "{$slotB->start_time} - {$slotB->end_time}");
        });

        $conflitsB = array_filter($previsuB['conflits_durs'], function ($msg) use ($slotA) {
            return ! str_contains($msg, "ID {$slotA->id}") && ! str_contains($msg, "{$slotA->start_time} - {$slotA->end_time}");
        });

        $conflitsA = array_values($conflitsA);
        $conflitsB = array_values($conflitsB);

        $alertes = array_values(array_unique(array_merge($previsuA['alertes_qualite'], $previsuB['alertes_qualite'])));
        $faisable = empty($conflitsA) && empty($conflitsB);

        return [
            'faisable' => $faisable,
            'conflits_a' => $conflitsA,
            'conflits_b' => $conflitsB,
            'alertes' => $alertes,
        ];
    }

    /**
     * Échange atomiquement les positions de deux créneaux.
     *
     * @return array{slot_a: ScheduleSlot, slot_b: ScheduleSlot}
     *
     * @throws DomainException
     */
    public function echangerCreneaux(
        ScheduleSlot $slotA,
        ScheduleSlot $slotB,
        ?int $userId = null,
        ?string $motif = null
    ): array {
        if ($slotA->schedule?->estVerrouille() || $slotB->schedule?->estVerrouille()) {
            throw new DomainException('Impossible d\'échanger des créneaux appartenant à un planning verrouillé.');
        }

        $previsu = $this->previsualiserEchange($slotA, $slotB);
        if (! $previsu['faisable']) {
            $tousConflits = array_merge($previsu['conflits_a'], $previsu['conflits_b']);
            throw new DomainException('Échange impossible : '.implode(' | ', $tousConflits));
        }

        return DB::transaction(function () use ($slotA, $slotB, $userId, $motif) {
            $posA = [
                'day_of_week' => $slotA->day_of_week,
                'start_time' => $slotA->start_time,
                'end_time' => $slotA->end_time,
                'room_id' => $slotA->room_id,
            ];

            $posB = [
                'day_of_week' => $slotB->day_of_week,
                'start_time' => $slotB->start_time,
                'end_time' => $slotB->end_time,
                'room_id' => $slotB->room_id,
            ];

            $slotA->update($posB);
            ScheduleChange::create([
                'schedule_slot_id' => $slotA->id,
                'changed_by' => $userId,
                'change_type' => 'time_changed',
                'old_values' => $posA,
                'new_values' => $posB,
                'reason' => $motif ?? "Échange atomique avec le créneau #{$slotB->id}",
            ]);

            $slotB->update($posA);
            ScheduleChange::create([
                'schedule_slot_id' => $slotB->id,
                'changed_by' => $userId,
                'change_type' => 'time_changed',
                'old_values' => $posB,
                'new_values' => $posA,
                'reason' => $motif ?? "Échange atomique avec le créneau #{$slotA->id}",
            ]);

            return [
                'slot_a' => $slotA->fresh(),
                'slot_b' => $slotB->fresh(),
            ];
        });
    }

    /**
     * Redimensionne la durée d'un créneau (ajustement de l'heure de fin via drag-handle).
     *
     * @throws DomainException
     */
    public function redimensionnerCreneau(
        ScheduleSlot $slot,
        string $nouvelleHeureFin,
        ?int $userId = null,
        ?string $motif = null
    ): ScheduleSlot {
        return $this->deplacerCreneau(
            $slot,
            $slot->day_of_week,
            $slot->start_time,
            $nouvelleHeureFin,
            $slot->room_id,
            $userId,
            $motif ?? 'Redimensionnement de durée de séance'
        );
    }
}
