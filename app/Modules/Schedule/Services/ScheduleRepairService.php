<?php

namespace App\Modules\Schedule\Services;

use App\Modules\Schedule\Models\RemplacementCandidature;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\ScheduleAvailability;
use App\Modules\Schedule\Models\ScheduleChange;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Service de réparation ciblée d'emploi du temps (Phase 8) :
 * détection d'imprévus (absence, salle indisponible), recherche intelligente
 * de remplaçants qualifiés et disponibles, alternatives de salles et résolutions assistées.
 */
class ScheduleRepairService
{
    public function __construct(
        protected ScheduleConflictService $conflictService,
        protected ScheduleConstraintChecker $constraintChecker,
        protected ScheduleInteractiveService $interactiveService
    ) {}

    /**
     * Recherche et classe par pertinence les enseignants qualifiés et libres
     * capables de remplacer l'enseignant titulaire sur un créneau donné.
     *
     * @return array<int, array{
     *     teacher: Teacher,
     *     teacher_id: int,
     *     nom_complet: string,
     *     charge_hebdo_heures: float,
     *     deja_present_ce_jour: bool,
     *     score_pertinence: int,
     *     justification: string
     * }>
     */
    public function suggererRemplacants(ScheduleSlot $slot, ?string $critere = 'pertinence'): array
    {
        $matiere = $slot->matiere;
        if (! $matiere) {
            return [];
        }

        $candidats = Teacher::where('actif', true)
            ->where('id', '!=', $slot->teacher_id)
            ->get();

        $remplacants = [];

        foreach ($candidats as $teacher) {
            // 1. Vérification stricte des qualifications
            if (! $teacher->estQualifiePour($matiere->code)) {
                continue;
            }

            // 2. Vérification des indisponibilités déclarées
            $indispo = ScheduleAvailability::where('teacher_id', $teacher->id)
                ->where('day_of_week', $slot->day_of_week)
                ->where('type', 'indisponible')
                ->where(function ($q) use ($slot) {
                    $q->where('start_time', '<', $slot->end_time)
                        ->where('end_time', '>', $slot->start_time);
                })
                ->exists();

            if ($indispo) {
                continue;
            }

            // 3. Vérification de chevauchement avec un cours existant du remplaçant
            $coursOccupe = ScheduleSlot::where(function ($q) use ($teacher) {
                $q->where('teacher_id', $teacher->id)
                    ->orWhereHas('teachersPivot', fn ($p) => $p->where('teacher_id', $teacher->id));
            })
                ->where('day_of_week', $slot->day_of_week)
                ->where('id', '!=', $slot->id)
                ->where('status', '!=', 'cancelled')
                ->where(function ($q) use ($slot) {
                    $q->where('start_time', '<', $slot->end_time)
                        ->where('end_time', '>', $slot->start_time);
                })
                ->first();

            if ($coursOccupe && $slot->conflitSemainesAvec($coursOccupe->semaine_parite, $coursOccupe->semaines_specifiques)) {
                continue;
            }

            // Calcul des indicateurs pédagogiques
            $chargeHebdo = $teacher->chargeHebdoPlanifiee($slot->schedule_id);

            $dejaPresent = ScheduleSlot::where(function ($q) use ($teacher) {
                $q->where('teacher_id', $teacher->id)
                    ->orWhereHas('teachersPivot', fn ($p) => $p->where('teacher_id', $teacher->id));
            })
                ->where('day_of_week', $slot->day_of_week)
                ->where('id', '!=', $slot->id)
                ->where('status', '!=', 'cancelled')
                ->exists();

            // Calcul du score d'adéquation (0-100)
            $score = 70;
            $justifs = ['Qualifié pour dispenser '.$matiere->nom];

            // LOT 09 : volontariat déclaré pour cette matière — signal fort,
            // la candidature active prime sur toute heuristique.
            $candidatDeclare = RemplacementCandidature::where('teacher_id', $teacher->id)
                ->where('matiere_id', $matiere->id)
                ->where('statut', 'soumise')
                ->exists();

            if ($candidatDeclare) {
                $score += 30;
                $justifs[] = 'Candidature de remplacement déclarée pour cette matière';
            }

            if ($dejaPresent) {
                $score += 15;
                $justifs[] = 'Déjà présent sur le campus ce jour-là';
            } else {
                $justifs[] = 'Non présent sur le campus ce jour';
            }

            if ($chargeHebdo < 12.0) {
                $score += 15;
                $justifs[] = "Charge hebdomadaire allégée ({$chargeHebdo}h)";
            } elseif ($chargeHebdo > 18.0) {
                $score -= 15;
                $justifs[] = "Charge hebdomadaire déjà chargée ({$chargeHebdo}h)";
            }

            $remplacants[] = [
                'teacher' => $teacher,
                'teacher_id' => $teacher->id,
                'nom_complet' => $teacher->nomComplet(),
                'charge_hebdo_heures' => $chargeHebdo,
                'deja_present_ce_jour' => $dejaPresent,
                'candidat_declare' => $candidatDeclare,
                'score_pertinence' => min(100, max(0, $score)),
                'justification' => implode(' | ', $justifs),
            ];
        }

        // Tri des résultats
        usort($remplacants, function ($a, $b) use ($critere) {
            if ($critere === 'charge_minimale') {
                return $a['charge_hebdo_heures'] <=> $b['charge_hebdo_heures'];
            }

            // Par défaut : score de pertinence décroissant
            return $b['score_pertinence'] <=> $a['score_pertinence'];
        });

        return $remplacants;
    }

    /**
     * Applique un remplacement d'enseignant sous transaction avec historisation complète.
     *
     * @throws DomainException
     */
    public function remplacerEnseignant(
        ScheduleSlot $slot,
        int $nouveauTeacherId,
        ?int $userId = null,
        ?string $motif = null
    ): ScheduleSlot {
        /** @var Teacher|null $nouveauTeacher */
        $nouveauTeacher = Teacher::find($nouveauTeacherId);
        if (! $nouveauTeacher) {
            throw new DomainException("L'enseignant remplaçant (ID {$nouveauTeacherId}) n'existe pas.");
        }

        if (! $nouveauTeacher->actif) {
            throw new DomainException("L'enseignant remplaçant '{$nouveauTeacher->nomComplet()}' n'est pas actif.");
        }

        if ($slot->matiere && ! $nouveauTeacher->estQualifiePour($slot->matiere->code)) {
            throw new DomainException("L'enseignant remplaçant '{$nouveauTeacher->nomComplet()}' n'est pas qualifié pour la matière {$slot->matiere->code}.");
        }

        // Vérification de disponibilité immédiate
        $indispo = ScheduleAvailability::where('teacher_id', $nouveauTeacherId)
            ->where('day_of_week', $slot->day_of_week)
            ->where('type', 'indisponible')
            ->where(function ($q) use ($slot) {
                $q->where('start_time', '<', $slot->end_time)
                    ->where('end_time', '>', $slot->start_time);
            })
            ->first();

        if ($indispo) {
            throw new DomainException("L'enseignant remplaçant est indisponible sur ce créneau : {$indispo->reason}");
        }

        return DB::transaction(function () use ($slot, $nouveauTeacher, $userId, $motif) {
            $ancienTeacherId = $slot->teacher_id;

            $slot->update([
                'teacher_id' => $nouveauTeacher->id,
            ]);

            ScheduleChange::create([
                'schedule_slot_id' => $slot->id,
                'changed_by' => $userId,
                'change_type' => 'teacher_changed',
                'old_values' => ['teacher_id' => $ancienTeacherId],
                'new_values' => ['teacher_id' => $nouveauTeacher->id],
                'reason' => $motif ?? "Remplacement par {$nouveauTeacher->nomComplet()}",
            ]);

            return $slot->fresh(['teacher', 'room', 'matiere']);
        });
    }

    /**
     * Recherche des salles alternatives disponibles, conformes aux effectifs et aux équipements.
     *
     * @return array<int, array{
     *     room: Room,
     *     room_id: int,
     *     nom: string,
     *     capacite: int,
     *     meme_batiment: bool,
     *     score_pertinence: int
     * }>
     */
    public function suggererSallesAlternatives(ScheduleSlot $slot): array
    {
        $batimentActuel = $slot->room?->batiment;
        $effectif = $slot->schedule?->groupe?->capacite ?? 0;

        $salles = Room::where('actif', true)
            ->where('id', '!=', $slot->room_id)
            ->get();

        $alternatives = [];

        foreach ($salles as $salle) {
            // Vérifier horaires d'ouverture
            if ($salle->heure_ouverture && $slot->start_time < $salle->heure_ouverture) {
                continue;
            }
            if ($salle->heure_fermeture && $slot->end_time > $salle->heure_fermeture) {
                continue;
            }

            // Vérifier équipements requis
            $manquants = $slot->conflitEquipementsAvec($salle);
            if (! empty($manquants)) {
                continue;
            }

            // Vérifier occupation
            $occupee = ScheduleSlot::where('room_id', $salle->id)
                ->where('day_of_week', $slot->day_of_week)
                ->where('id', '!=', $slot->id)
                ->where('status', '!=', 'cancelled')
                ->where(function ($q) use ($slot) {
                    $q->where('start_time', '<', $slot->end_time)
                        ->where('end_time', '>', $slot->start_time);
                })
                ->first();

            if ($occupee && $slot->conflitSemainesAvec($occupee->semaine_parite, $occupee->semaines_specifiques)) {
                continue;
            }

            $memeBatiment = ($batimentActuel !== null && $salle->batiment === $batimentActuel);
            $capaciteSuffisante = ($effectif === 0 || $salle->capacite >= $effectif);

            $score = 70;
            if ($memeBatiment) {
                $score += 20;
            }
            if (! $capaciteSuffisante) {
                $score -= 30;
            }

            $alternatives[] = [
                'room' => $salle,
                'room_id' => $salle->id,
                'nom' => $salle->nom,
                'capacite' => $salle->capacite,
                'meme_batiment' => $memeBatiment,
                'score_pertinence' => min(100, max(0, $score)),
            ];
        }

        usort($alternatives, fn ($a, $b) => $b['score_pertinence'] <=> $a['score_pertinence']);

        return $alternatives;
    }

    /**
     * Applique une réaffectation de salle sous transaction avec historisation.
     *
     * @throws DomainException
     */
    public function remplacerSalle(
        ScheduleSlot $slot,
        int $nouvelleSalleId,
        ?int $userId = null,
        ?string $motif = null
    ): ScheduleSlot {
        /** @var Room|null $salle */
        $salle = Room::find($nouvelleSalleId);
        if (! $salle) {
            throw new DomainException("La salle (ID {$nouvelleSalleId}) n'existe pas.");
        }

        if (! $salle->actif) {
            throw new DomainException("La salle '{$salle->nom}' est fermée ou inactive.");
        }

        return DB::transaction(function () use ($slot, $salle, $userId, $motif) {
            $ancienneSalleId = $slot->room_id;

            $slot->update(['room_id' => $salle->id]);

            ScheduleChange::create([
                'schedule_slot_id' => $slot->id,
                'changed_by' => $userId,
                'change_type' => 'room_changed',
                'old_values' => ['room_id' => $ancienneSalleId],
                'new_values' => ['room_id' => $salle->id],
                'reason' => $motif ?? "Changement de salle vers {$salle->nom}",
            ]);

            return $slot->fresh(['room', 'teacher', 'matiere']);
        });
    }

    /**
     * Diagnostique un créneau spécifique et propose un ensemble de solutions de réparation.
     *
     * @return array{
     *     slot_id: int,
     *     en_conflit: bool,
     *     problemes: array<int, string>,
     *     remplacants_suggeres: array<int, mixed>,
     *     salles_suggerees: array<int, mixed>
     * }
     */
    public function diagnostiquerEtProposerSolutions(ScheduleSlot $slot): array
    {
        $problemes = [];

        // Conflit enseignant
        $conflitsProf = $this->conflictService->conflitsEnseignant(
            $slot->day_of_week,
            $slot->start_time,
            $slot->end_time,
            $slot->teacher_id,
            $slot->id,
            $slot->semaine_parite ?? 'toutes',
            $slot->semaines_specifiques
        );
        foreach ($conflitsProf as $c) {
            $problemes[] = "Enseignant indisponible ou en conflit : {$c}";
        }

        // Conflit salle
        if ($slot->room_id) {
            $conflitsSalle = $this->conflictService->conflitsSalle(
                $slot->day_of_week,
                $slot->start_time,
                $slot->end_time,
                $slot->room_id,
                $slot->id,
                $slot->semaine_parite ?? 'toutes',
                $slot->semaines_specifiques
            );
            foreach ($conflitsSalle as $c) {
                $problemes[] = "Salle occupée ou indisponible : {$c}";
            }
        }

        $enConflit = ! empty($problemes);

        return [
            'slot_id' => $slot->id,
            'en_conflit' => $enConflit,
            'problemes' => $problemes,
            'remplacants_suggeres' => $this->suggererRemplacants($slot),
            'salles_suggerees' => $this->suggererSallesAlternatives($slot),
        ];
    }
}
