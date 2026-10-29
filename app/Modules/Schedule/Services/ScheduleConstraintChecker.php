<?php

namespace App\Modules\Schedule\Services;

use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleAvailability;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\TimetableConstraint;
use Illuminate\Support\Collection;

/**
 * PHASE 3 (Smart Timetables) — Vérificateur indépendant des contraintes d'emploi du temps.
 * Évalue rigoureusement les contraintes dures (bloquantes) et souples (qualitatives)
 * pour produire un ScheduleAuditReport complet, transparent et actionnable.
 */
class ScheduleConstraintChecker
{
    public function __construct(
        private readonly ScheduleConflictService $conflictService,
        private readonly ScheduleConstraintRegistry $registry,
        private readonly ScheduleCalendarService $calendarService,
    ) {}

    /**
     * Réalise l'audit complet d'un planning.
     */
    public function auditerPlanning(Schedule $schedule, ?int $faculteId = null): ScheduleAuditReport
    {
        $faculteId ??= $schedule->groupe?->faculte_id;

        $slots = $schedule->slots()
            ->where('status', '!=', 'cancelled')
            ->with([
                'matiere',
                'teacher',
                'coEnseignants',
                'room.batimentRelation',
                'schedule.groupe',
                'enseignement',
                'sousGroupe',
            ])
            ->get();

        return $this->auditerCreneaux($slots, $faculteId, $schedule);
    }

    /**
     * Réalise l'audit sur un ensemble arbitraire de créneaux.
     *
     * @param  iterable<ScheduleSlot>  $slots
     */
    public function auditerCreneaux(iterable $slots, ?int $faculteId = null, ?Schedule $schedule = null): ScheduleAuditReport
    {
        $collection = $slots instanceof Collection ? $slots : collect($slots);

        $violationsDures = [];
        $penalitesSouples = [];
        $recommandations = [];
        $signaturesViolations = [];

        // 1. ÉVALUATION DES CONTRAINTES DURES
        $this->evaluerContraintesDures(
            $collection,
            $faculteId,
            $schedule,
            $violationsDures,
            $signaturesViolations,
            $recommandations
        );

        // 2. ÉVALUATION DES CONTRAINTES SOUPLES
        $metriquesTrous = [
            'etudiants_minutes' => 0,
            'enseignants_minutes' => 0,
        ];

        $this->evaluerContraintesSouples(
            $collection,
            $faculteId,
            $schedule,
            $penalitesSouples,
            $recommandations,
            $metriquesTrous
        );

        // 3. CALCUL DES MÉTRIQUES GLOBALES
        $metriques = $this->calculerMetriques($collection, $schedule, $metriquesTrous, count($violationsDures), count($penalitesSouples));

        // 4. CALCUL DU SCORE GLOBAL (0 à 100)
        $totalPenalites = (float) array_sum(array_column($penalitesSouples, 'score_penalite'));
        $scoreQualite = (int) max(0, min(100, round(100 - ($totalPenalites * 0.5))));

        $estValide = count($violationsDures) === 0;

        return new ScheduleAuditReport(
            valide: $estValide,
            scoreQualite: $scoreQualite,
            violationsDures: array_values($violationsDures),
            penalitesSouples: array_values($penalitesSouples),
            metriques: $metriques,
            recommandations: array_values(array_unique($recommandations)),
        );
    }

    /**
     * Évaluation détaillée de chaque contrainte dure.
     *
     * @param  Collection<int, ScheduleSlot>  $slots
     * @param  array<string, mixed>  $violationsDures
     * @param  array<string, bool>  $signaturesViolations
     * @param  array<int, string>  $recommandations
     */
    private function evaluerContraintesDures(
        Collection $slots,
        ?int $faculteId,
        ?Schedule $schedule,
        array &$violationsDures,
        array &$signaturesViolations,
        array &$recommandations
    ): void {
        $actifOrphan = $this->registry->estActive(TimetableConstraint::HARD_ORPHAN_SLOT, $faculteId);
        $actifGroupe = $this->registry->estActive(TimetableConstraint::HARD_GROUP_CONFLICT, $faculteId);
        $actifTeacher = $this->registry->estActive(TimetableConstraint::HARD_TEACHER_CONFLICT, $faculteId);
        $actifRoom = $this->registry->estActive(TimetableConstraint::HARD_ROOM_CONFLICT, $faculteId);
        $actifCapacity = $this->registry->estActive(TimetableConstraint::HARD_ROOM_CAPACITY, $faculteId);
        $actifEquipment = $this->registry->estActive(TimetableConstraint::HARD_MISSING_EQUIPMENT, $faculteId);
        $actifQualif = $this->registry->estActive(TimetableConstraint::HARD_TEACHER_QUALIFICATION, $faculteId);
        $actifAvail = $this->registry->estActive(TimetableConstraint::HARD_TEACHER_UNAVAILABLE, $faculteId)
            || $this->registry->estActive(TimetableConstraint::HARD_ROOM_UNAVAILABLE, $faculteId);
        $actifPause = $this->registry->estActive(TimetableConstraint::HARD_INSTITUTIONAL_PAUSE, $faculteId);
        $actifOpening = $this->registry->estActive(TimetableConstraint::HARD_ROOM_OPENING_HOURS, $faculteId);

        foreach ($slots as $slot) {
            $slotSchedule = $schedule ?? $slot->schedule;
            $slotFaculteId = $faculteId ?? $slotSchedule?->groupe?->faculte_id;
            $nomMatiere = $slot->matiere?->nom ?? $slot->matiere?->code ?? 'Matière inconnue';
            $horaireStr = "{$slot->day_of_week} {$slot->start_time}–{$slot->end_time}";
            $ignorerIds = $slot->id ? [$slot->id] : ($slotSchedule?->exists ? $slotSchedule->slots()->pluck('id')->all() : null);

            // a) Séance orpheline (aucun enseignant)
            if ($actifOrphan && $slot->teacher_id === null && $slot->coEnseignants->isEmpty()) {
                $cle = "orphan_{$slot->id}_{$slot->day_of_week}_{$slot->start_time}";
                if (! isset($signaturesViolations[$cle])) {
                    $signaturesViolations[$cle] = true;
                    $violationsDures[] = [
                        'code' => TimetableConstraint::HARD_ORPHAN_SLOT,
                        'nom' => 'Séance sans enseignant affecté',
                        'slot_id' => $slot->id,
                        'matiere' => $nomMatiere,
                        'jour' => $slot->day_of_week,
                        'debut' => (string) $slot->start_time,
                        'fin' => (string) $slot->end_time,
                        'message' => "La séance {$nomMatiere} ({$horaireStr}) n'a aucun enseignant affecté.",
                        'details' => ['slot_id' => $slot->id],
                    ];
                    $recommandations[] = "Affecter un enseignant titulaire ou co-enseignant à la séance « {$nomMatiere} » ({$horaireStr}).";
                }
            }

            // b) Conflits de groupe
            if ($actifGroupe && $slot->schedule_id !== null) {
                $conflits = $this->conflictService->conflitsGroupe(
                    $slot->schedule_id,
                    $slot->day_of_week,
                    (string) $slot->start_time,
                    (string) $slot->end_time,
                    $ignorerIds,
                    $slot->semaine_parite ?? 'toutes',
                    $slot->semaines_specifiques,
                    $slot->sous_groupe_id
                );
                foreach ($conflits as $msg) {
                    $cle = 'groupe_'.md5($msg);
                    if (! isset($signaturesViolations[$cle])) {
                        $signaturesViolations[$cle] = true;
                        $violationsDures[] = [
                            'code' => TimetableConstraint::HARD_GROUP_CONFLICT,
                            'nom' => 'Chevauchement de cours pour un groupe',
                            'slot_id' => $slot->id,
                            'matiere' => $nomMatiere,
                            'jour' => $slot->day_of_week,
                            'debut' => (string) $slot->start_time,
                            'fin' => (string) $slot->end_time,
                            'message' => $msg,
                            'details' => ['slot_id' => $slot->id],
                        ];
                    }
                }
            }

            // c) Conflits enseignant (titulaire + co-enseignants)
            if ($actifTeacher) {
                $tousEns = array_values(array_filter(array_unique(array_merge(
                    $slot->teacher_id !== null ? [$slot->teacher_id] : [],
                    $slot->coEnseignants->pluck('id')->all()
                ))));

                foreach ($tousEns as $ensId) {
                    $conflits = $this->conflictService->conflitsEnseignant(
                        $slot->day_of_week,
                        (string) $slot->start_time,
                        (string) $slot->end_time,
                        $ensId,
                        $ignorerIds,
                        $slot->semaine_parite ?? 'toutes',
                        $slot->semaines_specifiques
                    );
                    foreach ($conflits as $msg) {
                        $cle = 'teacher_'.md5($msg);
                        if (! isset($signaturesViolations[$cle])) {
                            $signaturesViolations[$cle] = true;
                            $violationsDures[] = [
                                'code' => TimetableConstraint::HARD_TEACHER_CONFLICT,
                                'nom' => "Chevauchement d'horaire enseignant",
                                'slot_id' => $slot->id,
                                'matiere' => $nomMatiere,
                                'jour' => $slot->day_of_week,
                                'debut' => (string) $slot->start_time,
                                'fin' => (string) $slot->end_time,
                                'message' => $msg,
                                'details' => ['slot_id' => $slot->id, 'teacher_id' => $ensId],
                            ];
                        }
                    }
                }
            }

            // d) Conflits de salle
            if ($actifRoom && $slot->room_id !== null) {
                $conflits = $this->conflictService->conflitsSalle(
                    $slot->day_of_week,
                    (string) $slot->start_time,
                    (string) $slot->end_time,
                    $slot->room_id,
                    $ignorerIds,
                    $slot->semaine_parite ?? 'toutes',
                    $slot->semaines_specifiques
                );
                foreach ($conflits as $msg) {
                    $cle = 'room_'.md5($msg);
                    if (! isset($signaturesViolations[$cle])) {
                        $signaturesViolations[$cle] = true;
                        $violationsDures[] = [
                            'code' => TimetableConstraint::HARD_ROOM_CONFLICT,
                            'nom' => "Chevauchement d'occupation de salle",
                            'slot_id' => $slot->id,
                            'matiere' => $nomMatiere,
                            'jour' => $slot->day_of_week,
                            'debut' => (string) $slot->start_time,
                            'fin' => (string) $slot->end_time,
                            'message' => $msg,
                            'details' => ['slot_id' => $slot->id, 'room_id' => $slot->room_id],
                        ];
                    }
                }
            }

            // e) Dépassement de capacité
            if ($actifCapacity && $slot->room_id !== null) {
                $effectif = $this->conflictService->resoudreEffectif($slotSchedule, null, $slot->sous_groupe_id);
                $conflits = $this->conflictService->conflitsCapacite($slot->room_id, $effectif);
                foreach ($conflits as $msg) {
                    $cle = "cap_{$slot->id}_{$slot->room_id}";
                    if (! isset($signaturesViolations[$cle])) {
                        $signaturesViolations[$cle] = true;
                        $violationsDures[] = [
                            'code' => TimetableConstraint::HARD_ROOM_CAPACITY,
                            'nom' => 'Dépassement de la capacité de la salle',
                            'slot_id' => $slot->id,
                            'matiere' => $nomMatiere,
                            'jour' => $slot->day_of_week,
                            'debut' => (string) $slot->start_time,
                            'fin' => (string) $slot->end_time,
                            'message' => $msg,
                            'details' => ['slot_id' => $slot->id, 'room_id' => $slot->room_id, 'effectif' => $effectif],
                        ];
                        $recommandations[] = "Changer la salle de la séance « {$nomMatiere} » pour une salle plus grande ou réduire l'effectif.";
                    }
                }
            }

            // f) Équipements requis
            if ($actifEquipment && $slot->room_id !== null && ! empty($slot->equipements_requis)) {
                $conflits = $this->conflictService->conflitsEquipements($slot->room_id, $slot->equipements_requis);
                foreach ($conflits as $msg) {
                    $cle = "equip_{$slot->id}_{$slot->room_id}";
                    if (! isset($signaturesViolations[$cle])) {
                        $signaturesViolations[$cle] = true;
                        $violationsDures[] = [
                            'code' => TimetableConstraint::HARD_MISSING_EQUIPMENT,
                            'nom' => 'Équipements pédagogiques indispensables manquants',
                            'slot_id' => $slot->id,
                            'matiere' => $nomMatiere,
                            'jour' => $slot->day_of_week,
                            'debut' => (string) $slot->start_time,
                            'fin' => (string) $slot->end_time,
                            'message' => $msg,
                            'details' => ['slot_id' => $slot->id, 'room_id' => $slot->room_id],
                        ];
                    }
                }
            }

            // g) Qualifications enseignant
            if ($actifQualif && $slot->matiere_id !== null) {
                $tousEns = array_values(array_filter(array_unique(array_merge(
                    $slot->teacher_id !== null ? [$slot->teacher_id] : [],
                    $slot->coEnseignants->pluck('id')->all()
                ))));
                $conflits = $this->conflictService->conflitsQualifications($tousEns, $slot->matiere_id);
                foreach ($conflits as $msg) {
                    $cle = "qualif_{$slot->id}_".md5($msg);
                    if (! isset($signaturesViolations[$cle])) {
                        $signaturesViolations[$cle] = true;
                        $violationsDures[] = [
                            'code' => TimetableConstraint::HARD_TEACHER_QUALIFICATION,
                            'nom' => "Enseignant non qualifié pour l'enseignement",
                            'slot_id' => $slot->id,
                            'matiere' => $nomMatiere,
                            'jour' => $slot->day_of_week,
                            'debut' => (string) $slot->start_time,
                            'fin' => (string) $slot->end_time,
                            'message' => $msg,
                            'details' => ['slot_id' => $slot->id, 'matiere_id' => $slot->matiere_id],
                        ];
                    }
                }
            }

            // h) Disponibilités / Indisponibilités déclarées
            if ($actifAvail && $slotSchedule !== null) {
                $conflits = $this->conflictService->conflitsDisponibilites(
                    $slotSchedule,
                    $slot->day_of_week,
                    (string) $slot->start_time,
                    (string) $slot->end_time,
                    $slot->teacher_id,
                    $slot->room_id
                );
                foreach ($conflits as $msg) {
                    $cle = 'avail_'.md5($msg);
                    if (! isset($signaturesViolations[$cle])) {
                        $signaturesViolations[$cle] = true;
                        $code = str_contains($msg, 'Salle')
                            ? TimetableConstraint::HARD_ROOM_UNAVAILABLE
                            : TimetableConstraint::HARD_TEACHER_UNAVAILABLE;

                        $violationsDures[] = [
                            'code' => $code,
                            'nom' => $code === TimetableConstraint::HARD_ROOM_UNAVAILABLE ? 'Salle indisponible ou fermée' : "Indisponibilité déclarée d'un enseignant",
                            'slot_id' => $slot->id,
                            'matiere' => $nomMatiere,
                            'jour' => $slot->day_of_week,
                            'debut' => (string) $slot->start_time,
                            'fin' => (string) $slot->end_time,
                            'message' => $msg,
                            'details' => ['slot_id' => $slot->id],
                        ];
                    }
                }
            }

            // i) Pauses obligatoires
            if ($actifPause) {
                $conflits = $this->conflictService->conflitsPauses(
                    (string) $slot->start_time,
                    (string) $slot->end_time,
                    $slot->day_of_week,
                    $slotFaculteId
                );
                foreach ($conflits as $msg) {
                    $cle = 'pause_'.md5($msg);
                    if (! isset($signaturesViolations[$cle])) {
                        $signaturesViolations[$cle] = true;
                        $violationsDures[] = [
                            'code' => TimetableConstraint::HARD_INSTITUTIONAL_PAUSE,
                            'nom' => 'Séance sur pause institutionnelle obligatoire',
                            'slot_id' => $slot->id,
                            'matiere' => $nomMatiere,
                            'jour' => $slot->day_of_week,
                            'debut' => (string) $slot->start_time,
                            'fin' => (string) $slot->end_time,
                            'message' => $msg,
                            'details' => ['slot_id' => $slot->id],
                        ];
                    }
                }
            }

            // j) Horaires d'ouverture de salle
            if ($actifOpening && $slot->room_id !== null) {
                $conflits = $this->conflictService->conflitsHorairesSalle(
                    $slot->room_id,
                    (string) $slot->start_time,
                    (string) $slot->end_time
                );
                foreach ($conflits as $msg) {
                    $cle = "open_{$slot->id}_{$slot->room_id}";
                    if (! isset($signaturesViolations[$cle])) {
                        $signaturesViolations[$cle] = true;
                        $violationsDures[] = [
                            'code' => TimetableConstraint::HARD_ROOM_OPENING_HOURS,
                            'nom' => "Séance hors horaires d'ouverture de la salle",
                            'slot_id' => $slot->id,
                            'matiere' => $nomMatiere,
                            'jour' => $slot->day_of_week,
                            'debut' => (string) $slot->start_time,
                            'fin' => (string) $slot->end_time,
                            'message' => $msg,
                            'details' => ['slot_id' => $slot->id, 'room_id' => $slot->room_id],
                        ];
                    }
                }
            }
        }
    }

    /**
     * Évaluation détaillée des contraintes souples avec calcul des pénalités.
     *
     * @param  Collection<int, ScheduleSlot>  $slots
     * @param  array<int, array<string, mixed>>  $penalitesSouples
     * @param  array<int, string>  $recommandations
     * @param  array{etudiants_minutes: int, enseignants_minutes: int}  $metriquesTrous
     */
    private function evaluerContraintesSouples(
        Collection $slots,
        ?int $faculteId,
        ?Schedule $schedule,
        array &$penalitesSouples,
        array &$recommandations,
        array &$metriquesTrous
    ): void {
        $parJour = $slots->groupBy('day_of_week');

        // a) SOFT_STUDENT_IDLE_GAPS & SOFT_MAX_CONSECUTIVE_HOURS & SOFT_MAX_DAILY_HOURS (Étudiants)
        $actifTrousEtu = $this->registry->estActive(TimetableConstraint::SOFT_STUDENT_IDLE_GAPS, $faculteId);
        $poidsTrousEtu = $this->registry->getPoids(TimetableConstraint::SOFT_STUDENT_IDLE_GAPS, $faculteId);
        $paramsTrousEtu = $this->registry->getParametres(TimetableConstraint::SOFT_STUDENT_IDLE_GAPS, $faculteId);

        $actifConsec = $this->registry->estActive(TimetableConstraint::SOFT_MAX_CONSECUTIVE_HOURS, $faculteId);
        $poidsConsec = $this->registry->getPoids(TimetableConstraint::SOFT_MAX_CONSECUTIVE_HOURS, $faculteId);
        $paramsConsec = $this->registry->getParametres(TimetableConstraint::SOFT_MAX_CONSECUTIVE_HOURS, $faculteId);

        $actifDaily = $this->registry->estActive(TimetableConstraint::SOFT_MAX_DAILY_HOURS, $faculteId);
        $poidsDaily = $this->registry->getPoids(TimetableConstraint::SOFT_MAX_DAILY_HOURS, $faculteId);
        $paramsDaily = $this->registry->getParametres(TimetableConstraint::SOFT_MAX_DAILY_HOURS, $faculteId);

        $actifTransit = $this->registry->estActive(TimetableConstraint::SOFT_BUILDING_TRANSIT, $faculteId);
        $poidsTransit = $this->registry->getPoids(TimetableConstraint::SOFT_BUILDING_TRANSIT, $faculteId);
        $paramsTransit = $this->registry->getParametres(TimetableConstraint::SOFT_BUILDING_TRANSIT, $faculteId);

        $actifOversized = $this->registry->estActive(TimetableConstraint::SOFT_ROOM_OVERSIZED, $faculteId);
        $poidsOversized = $this->registry->getPoids(TimetableConstraint::SOFT_ROOM_OVERSIZED, $faculteId);
        $paramsOversized = $this->registry->getParametres(TimetableConstraint::SOFT_ROOM_OVERSIZED, $faculteId);

        $heuresParJourEtu = [];

        foreach ($parJour as $jour => $slotsJour) {
            $ordonnes = $slotsJour->sortBy('start_time')->values();
            $totalMinutesJour = 0;
            $sequenceContinueMinutes = 0;

            for ($i = 0; $i < $ordonnes->count(); $i++) {
                $courant = $ordonnes[$i];
                $dureeCourant = $this->dureeMinutes((string) $courant->start_time, (string) $courant->end_time);
                $totalMinutesJour += $dureeCourant;

                if ($i === 0) {
                    $sequenceContinueMinutes = $dureeCourant;
                }

                // Vérification de salle surdimensionnée
                if ($actifOversized && $courant->room !== null && $courant->room->capacite !== null) {
                    $seuilMin = $paramsOversized['seuil_capacite_min'] ?? 60;
                    $ratioSeuil = $paramsOversized['ratio_seuil'] ?? 2.5;

                    if ($courant->room->capacite >= $seuilMin) {
                        $eff = $this->conflictService->resoudreEffectif($schedule ?? $courant->schedule, null, $courant->sous_groupe_id);
                        if ($eff > 0 && ($courant->room->capacite > ($eff * $ratioSeuil))) {
                            $pen = ($paramsOversized['penalite_surdimensionnement'] ?? 5) * ($poidsOversized / 10);
                            $ratio = round($courant->room->capacite / $eff, 1);
                            $penalitesSouples[] = [
                                'code' => TimetableConstraint::SOFT_ROOM_OVERSIZED,
                                'nom' => 'Prévention des salles surdimensionnées',
                                'score_penalite' => round($pen, 2),
                                'poids' => $poidsOversized,
                                'message' => "Salle « {$courant->room->nom} » surdimensionnée ({$courant->room->capacite} places) pour {$eff} étudiants (ratio {$ratio}).",
                                'details' => ['slot_id' => $courant->id, 'capacite' => $courant->room->capacite, 'effectif' => $eff],
                            ];
                        }
                    }
                }

                if ($i < $ordonnes->count() - 1) {
                    $suivant = $ordonnes[$i + 1];
                    $battement = $this->dureeMinutes((string) $courant->end_time, (string) $suivant->start_time);

                    if ($battement > 0) {
                        // Trous étudiants
                        $seuilPause = $paramsTrousEtu['seuil_pause_minutes'] ?? 30;
                        if ($actifTrousEtu && $battement > $seuilPause) {
                            $demiHeures = (int) ceil(($battement - $seuilPause) / 30);
                            $penalite = $demiHeures * ($paramsTrousEtu['penalite_par_30m'] ?? 5) * ($poidsTrousEtu / 10);
                            $metriquesTrous['etudiants_minutes'] += $battement;

                            $penalitesSouples[] = [
                                'code' => TimetableConstraint::SOFT_STUDENT_IDLE_GAPS,
                                'nom' => "Minimisation des temps d'attente (trous) étudiants",
                                'score_penalite' => round($penalite, 2),
                                'poids' => $poidsTrousEtu,
                                'message' => "Trou d'attente de {$battement} minutes le {$jour} entre {$courant->end_time} et {$suivant->start_time}.",
                                'details' => ['jour' => $jour, 'duree_minutes' => $battement, 'apres_slot_id' => $courant->id],
                            ];
                            $recommandations[] = "Rapprocher les cours le {$jour} pour réduire le trou de {$battement} min.";
                        }

                        // Transit inter-bâtiments
                        if ($actifTransit && $courant->room?->batiment_id !== null && $suivant->room?->batiment_id !== null) {
                            if ($courant->room->batiment_id !== $suivant->room->batiment_id) {
                                $tempsRequis = $paramsTransit['temps_transit_min'] ?? 15;
                                if ($battement < $tempsRequis) {
                                    $pen = ($paramsTransit['penalite_transit_insuffisant'] ?? 15) * ($poidsTransit / 10);
                                    $nomBatA = $courant->room->batimentRelation?->nom ?? (is_string($courant->room->batiment) ? $courant->room->batiment : "Bâtiment #{$courant->room->batiment_id}");
                                    $nomBatB = $suivant->room->batimentRelation?->nom ?? (is_string($suivant->room->batiment) ? $suivant->room->batiment : "Bâtiment #{$suivant->room->batiment_id}");

                                    $penalitesSouples[] = [
                                        'code' => TimetableConstraint::SOFT_BUILDING_TRANSIT,
                                        'nom' => 'Délai de battement pour transit inter-bâtiments',
                                        'score_penalite' => round($pen, 2),
                                        'poids' => $poidsTransit,
                                        'message' => "Changement de bâtiment ({$nomBatA} → {$nomBatB}) avec seulement {$battement} min de battement ({$tempsRequis} min requis).",
                                        'details' => ['jour' => $jour, 'battement' => $battement, 'batiment_source' => $nomBatA, 'batiment_cible' => $nomBatB],
                                    ];
                                }
                            }
                        }

                        // Réinitialiser la séquence continue si pause >= 20 min
                        if ($battement >= 20) {
                            $sequenceContinueMinutes = $dureeCourant;
                        } else {
                            $sequenceContinueMinutes += $dureeCourant;
                        }
                    } else {
                        // Cours enchaîné sans pause
                        $sequenceContinueMinutes += $dureeCourant;
                    }

                    // Heures consécutives sans pause
                    if ($actifConsec) {
                        $maxHeuresConsec = $paramsConsec['max_heures'] ?? 4;
                        $heuresConsec = $sequenceContinueMinutes / 60;
                        if ($heuresConsec > $maxHeuresConsec) {
                            $exces = $heuresConsec - $maxHeuresConsec;
                            $pen = $exces * ($paramsConsec['penalite_par_heure_supp'] ?? 10) * ($poidsConsec / 10);
                            $penalitesSouples[] = [
                                'code' => TimetableConstraint::SOFT_MAX_CONSECUTIVE_HOURS,
                                'nom' => "Plafond d'heures consécutives sans pause",
                                'score_penalite' => round($pen, 2),
                                'poids' => $poidsConsec,
                                'message' => "Séquence continue de {$heuresConsec}h sans pause suffisante pour les étudiants le {$jour}.",
                                'details' => ['jour' => $jour, 'heures' => $heuresConsec],
                            ];
                        }
                    }
                }
            }

            // Charge journalière étudiant
            $heuresJour = $totalMinutesJour / 60;
            $heuresParJourEtu[$jour] = $heuresJour;

            if ($actifDaily) {
                $maxJourEtu = $paramsDaily['max_heures_etudiant'] ?? 8;
                if ($heuresJour > $maxJourEtu) {
                    $exces = $heuresJour - $maxJourEtu;
                    $pen = $exces * ($paramsDaily['penalite_par_heure_supp'] ?? 10) * ($poidsDaily / 10);
                    $penalitesSouples[] = [
                        'code' => TimetableConstraint::SOFT_MAX_DAILY_HOURS,
                        'nom' => "Plafond de charge quotidienne d'heures",
                        'score_penalite' => round($pen, 2),
                        'poids' => $poidsDaily,
                        'message' => "Charge journalière étudiante de {$heuresJour}h le {$jour} (plafond : {$maxJourEtu}h).",
                        'details' => ['jour' => $jour, 'heures' => $heuresJour],
                    ];
                }
            }
        }

        // b) SOFT_TEACHER_IDLE_GAPS & CHARGE ENSEIGNANT & PREFERENCES SOUHAITEES
        $actifTrousEns = $this->registry->estActive(TimetableConstraint::SOFT_TEACHER_IDLE_GAPS, $faculteId);
        $poidsTrousEns = $this->registry->getPoids(TimetableConstraint::SOFT_TEACHER_IDLE_GAPS, $faculteId);
        $paramsTrousEns = $this->registry->getParametres(TimetableConstraint::SOFT_TEACHER_IDLE_GAPS, $faculteId);

        $actifPrefs = $this->registry->estActive(TimetableConstraint::SOFT_TEACHER_PREFERENCE, $faculteId);
        $poidsPrefs = $this->registry->getPoids(TimetableConstraint::SOFT_TEACHER_PREFERENCE, $faculteId);
        $paramsPrefs = $this->registry->getParametres(TimetableConstraint::SOFT_TEACHER_PREFERENCE, $faculteId);

        $parEnseignant = $slots->filter(fn ($s) => $s->teacher_id !== null)->groupBy('teacher_id');

        $souhaitsEnseignants = collect();
        $souhaitsSalles = collect();
        $souhaitsGroupes = collect();

        if ($actifPrefs) {
            $teacherIds = $parEnseignant->keys()->all();
            $roomIds = $slots->pluck('room_id')->filter()->unique()->all();
            $groupId = $schedule?->groupe_id ?? $slots->first()?->schedule?->groupe_id;

            $toutesPrefQuery = ScheduleAvailability::query()->where('type', 'souhaitee');
            $aConditions = false;

            $toutesPrefQuery->where(function ($q) use ($teacherIds, $roomIds, $groupId, &$aConditions) {
                if ($teacherIds !== []) {
                    $q->whereIn('teacher_id', $teacherIds);
                    $aConditions = true;
                }
                if ($roomIds !== []) {
                    if ($aConditions) {
                        $q->orWhereIn('room_id', $roomIds);
                    } else {
                        $q->whereIn('room_id', $roomIds);
                        $aConditions = true;
                    }
                }
                if ($groupId !== null) {
                    if ($aConditions) {
                        $q->orWhere('group_id', $groupId);
                    } else {
                        $q->where('group_id', $groupId);
                        $aConditions = true;
                    }
                }
            });

            if ($aConditions) {
                $tousSouhaits = $toutesPrefQuery->get();
                $souhaitsEnseignants = $tousSouhaits->whereNotNull('teacher_id')->groupBy('teacher_id');
                $souhaitsSalles = $tousSouhaits->whereNotNull('room_id')->groupBy('room_id');
                $souhaitsGroupes = $tousSouhaits->whereNotNull('group_id')->groupBy('group_id');
            }
        }

        foreach ($parEnseignant as $teacherId => $slotsEns) {
            $nomEns = $slotsEns->first()?->teacher?->user?->name ?? "Enseignant #{$teacherId}";
            $souhaits = $souhaitsEnseignants->get($teacherId);

            $parJourEns = $slotsEns->groupBy('day_of_week');

            foreach ($parJourEns as $jour => $creneauxDuJour) {
                $ord = $creneauxDuJour->sortBy('start_time')->values();

                for ($j = 0; $j < $ord->count() - 1; $j++) {
                    $c1 = $ord[$j];
                    $c2 = $ord[$j + 1];
                    $intervalle = $this->dureeMinutes((string) $c1->end_time, (string) $c2->start_time);

                    $seuilPauseEns = $paramsTrousEns['seuil_pause_minutes'] ?? 30;
                    if ($actifTrousEns && $intervalle > $seuilPauseEns) {
                        $demiHeures = (int) ceil(($intervalle - $seuilPauseEns) / 30);
                        $pen = $demiHeures * ($paramsTrousEns['penalite_par_30m'] ?? 4) * ($poidsTrousEns / 10);
                        $metriquesTrous['enseignants_minutes'] += $intervalle;

                        $penalitesSouples[] = [
                            'code' => TimetableConstraint::SOFT_TEACHER_IDLE_GAPS,
                            'nom' => "Minimisation des temps d'attente (trous) enseignants",
                            'score_penalite' => round($pen, 2),
                            'poids' => $poidsTrousEns,
                            'message' => "Trou de {$intervalle} minutes pour {$nomEns} le {$jour} entre {$c1->end_time} et {$c2->start_time}.",
                            'details' => ['teacher_id' => $teacherId, 'jour' => $jour, 'duree_minutes' => $intervalle],
                        ];
                    }
                }

                // Vérification du respect des souhaits déclarés
                if ($actifPrefs && $souhaits !== null && $souhaits->isNotEmpty()) {
                    foreach ($creneauxDuJour as $sc) {
                        $correspondASouhait = $souhaits->contains(function (ScheduleAvailability $pref) use ($sc) {
                            return $pref->day_of_week === $sc->day_of_week && $pref->chevauche((string) $sc->start_time, (string) $sc->end_time);
                        });

                        if (! $correspondASouhait) {
                            $pen = ($paramsPrefs['penalite_cours_hors_souhait'] ?? 8) * ($poidsPrefs / 10);
                            $penalitesSouples[] = [
                                'code' => TimetableConstraint::SOFT_TEACHER_PREFERENCE,
                                'nom' => 'Respect des préférences horaires des enseignants',
                                'score_penalite' => round($pen, 2),
                                'poids' => $poidsPrefs,
                                'message' => "Séance {$sc->matiere?->code} programmée le {$sc->day_of_week} en dehors des créneaux souhaités par {$nomEns}.",
                                'details' => ['teacher_id' => $teacherId, 'slot_id' => $sc->id],
                            ];
                        }
                    }
                }
            }
        }

        // Respect des préférences souhaitées déclarées pour les salles et les groupes (Phase C - R1)
        if ($actifPrefs) {
            // Salles :
            $parSalle = $slots->filter(fn ($s) => $s->room_id !== null)->groupBy('room_id');
            if ($parSalle->isNotEmpty() && $souhaitsSalles->isNotEmpty()) {
                foreach ($parSalle as $roomId => $slotsSalle) {
                    $souhaits = $souhaitsSalles->get($roomId);
                    if ($souhaits !== null && $souhaits->isNotEmpty()) {
                        $nomSalle = $slotsSalle->first()?->room?->code ?? "Salle #{$roomId}";
                        foreach ($slotsSalle as $sc) {
                            $correspond = $souhaits->contains(function (ScheduleAvailability $pref) use ($sc) {
                                return $pref->day_of_week === $sc->day_of_week && $pref->chevauche((string) $sc->start_time, (string) $sc->end_time);
                            });

                            if (! $correspond) {
                                $pen = ($paramsPrefs['penalite_cours_hors_souhait'] ?? 8) * ($poidsPrefs / 10);
                                $penalitesSouples[] = [
                                    'code' => TimetableConstraint::SOFT_TEACHER_PREFERENCE,
                                    'nom' => 'Respect des préférences horaires des salles',
                                    'score_penalite' => round($pen, 2),
                                    'poids' => $poidsPrefs,
                                    'message' => "Séance {$sc->matiere?->code} programmée le {$sc->day_of_week} en dehors des créneaux souhaités pour la salle {$nomSalle}.",
                                    'details' => ['room_id' => $roomId, 'slot_id' => $sc->id],
                                ];
                            }
                        }
                    }
                }
            }

            // Groupes :
            $groupId = $schedule?->groupe_id ?? $slots->first()?->schedule?->groupe_id;
            if ($groupId !== null && $souhaitsGroupes->isNotEmpty()) {
                $souhaits = $souhaitsGroupes->get($groupId);
                if ($souhaits !== null && $souhaits->isNotEmpty()) {
                    $nomGroupe = $schedule?->groupe?->nom ?? "Groupe #{$groupId}";
                    foreach ($slots as $sc) {
                        $correspond = $souhaits->contains(function (ScheduleAvailability $pref) use ($sc) {
                            return $pref->day_of_week === $sc->day_of_week && $pref->chevauche((string) $sc->start_time, (string) $sc->end_time);
                        });

                        if (! $correspond) {
                            $pen = ($paramsPrefs['penalite_cours_hors_souhait'] ?? 8) * ($poidsPrefs / 10);
                            $penalitesSouples[] = [
                                'code' => TimetableConstraint::SOFT_TEACHER_PREFERENCE,
                                'nom' => 'Respect des préférences horaires des groupes',
                                'score_penalite' => round($pen, 2),
                                'poids' => $poidsPrefs,
                                'message' => "Séance {$sc->matiere?->code} programmée le {$sc->day_of_week} en dehors des créneaux souhaités pour le groupe {$nomGroupe}.",
                                'details' => ['group_id' => $groupId, 'slot_id' => $sc->id],
                            ];
                        }
                    }
                }
            }
        }

        // c) SOFT_DAILY_EQUILIBRIUM (Équilibre de charge entre jours)
        $actifEquilibre = $this->registry->estActive(TimetableConstraint::SOFT_DAILY_EQUILIBRIUM, $faculteId);
        if ($actifEquilibre && count($heuresParJourEtu) >= 2) {
            $poidsEquil = $this->registry->getPoids(TimetableConstraint::SOFT_DAILY_EQUILIBRIUM, $faculteId);
            $paramsEquil = $this->registry->getParametres(TimetableConstraint::SOFT_DAILY_EQUILIBRIUM, $faculteId);

            $maxH = max($heuresParJourEtu);
            $minH = min($heuresParJourEtu);
            $ecart = $maxH - $minH;
            $ecartMax = $paramsEquil['ecart_max_tolere_heures'] ?? 4;

            if ($ecart > $ecartMax) {
                $depassement = $ecart - $ecartMax;
                $pen = $depassement * ($paramsEquil['penalite_par_heure_ecart'] ?? 5) * ($poidsEquil / 10);
                $penalitesSouples[] = [
                    'code' => TimetableConstraint::SOFT_DAILY_EQUILIBRIUM,
                    'nom' => 'Équilibre hebdomadaire de la charge journalière',
                    'score_penalite' => round($pen, 2),
                    'poids' => $poidsEquil,
                    'message' => "Déséquilibre de charge hebdomadaire : écart de {$ecart}h entre le jour le plus chargé ({$maxH}h) et le moins chargé ({$minH}h).",
                    'details' => ['max_heures' => $maxH, 'min_heures' => $minH, 'ecart' => $ecart],
                ];
            }
        }
    }

    /**
     * Calcule la synthèse métrique du planning.
     *
     * @param  Collection<int, ScheduleSlot>  $slots
     * @param  array{etudiants_minutes: int, enseignants_minutes: int}  $metriquesTrous
     * @return array<string, mixed>
     */
    private function calculerMetriques(
        Collection $slots,
        ?Schedule $schedule,
        array $metriquesTrous,
        int $nbViolationsDures,
        int $nbPenalitesSouples
    ): array {
        $totalMinutes = 0;
        $heuresParJour = [];
        $ratiosRemplissage = [];
        $sallesDistinctes = [];
        $enseignantsDistincts = [];

        foreach ($slots as $slot) {
            $duree = $this->dureeMinutes((string) $slot->start_time, (string) $slot->end_time);
            $totalMinutes += $duree;

            $jour = (string) $slot->day_of_week;
            $heuresParJour[$jour] = ($heuresParJour[$jour] ?? 0) + ($duree / 60);

            if ($slot->room_id !== null) {
                $sallesDistinctes[$slot->room_id] = true;
                if ($slot->room !== null && ($slot->room->capacite ?? 0) > 0) {
                    $eff = $this->conflictService->resoudreEffectif($schedule ?? $slot->schedule, null, $slot->sous_groupe_id);
                    $ratiosRemplissage[] = min(1.0, (float) ($eff / $slot->room->capacite));
                }
            }

            if ($slot->teacher_id !== null) {
                $enseignantsDistincts[$slot->teacher_id] = true;
            }
            foreach ($slot->coEnseignants as $coEns) {
                $enseignantsDistincts[$coEns->id] = true;
            }
        }

        $tauxMoyen = count($ratiosRemplissage) > 0
            ? round((array_sum($ratiosRemplissage) / count($ratiosRemplissage)) * 100, 1)
            : 0.0;

        return [
            'total_creneaux' => $slots->count(),
            'total_heures' => round($totalMinutes / 60, 2),
            'heures_par_jour' => array_map(fn ($h) => round($h, 2), $heuresParJour),
            'salles_utilisees' => count($sallesDistinctes),
            'enseignants_mobilises' => count($enseignantsDistincts),
            'taux_occupation_moyen_salles' => $tauxMoyen,
            'total_minutes_trous_etudiants' => $metriquesTrous['etudiants_minutes'],
            'total_minutes_trous_enseignants' => $metriquesTrous['enseignants_minutes'],
            'nb_violations_dures' => $nbViolationsDures,
            'nb_penalites_souples' => $nbPenalitesSouples,
        ];
    }

    private function dureeMinutes(string $debut, string $fin): int
    {
        $d = strtotime("1970-01-01 {$debut}");
        $f = strtotime("1970-01-01 {$fin}");

        if ($d === false || $f === false || $f < $d) {
            return 0;
        }

        return (int) round(($f - $d) / 60);
    }
}
