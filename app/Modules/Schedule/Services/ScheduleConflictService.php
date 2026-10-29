<?php

namespace App\Modules\Schedule\Services;

use App\Models\Matiere;
use App\Modules\Enseignement\Models\SousGroupe;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleAvailability;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Détection des conflits (§8) : réutilisable en création, modification,
 * déplacement et exception. Chevauchement strict (fin == début = OK).
 *
 * PHASE A (Smart Timetables) : les indisponibilités DÉCLARÉES
 * (schedule_availabilities, type `indisponible`) sont des contraintes
 * dures — elles rejoignent les conflits existants sans les remplacer.
 */
class ScheduleConflictService
{
    /**
     * @return array<int, string> Descriptions des conflits détectés.
     */
    public function verifier(
        int $scheduleId,
        string $jour,
        string $debut,
        string $fin,
        ?int $teacherId = null,
        ?int $roomId = null,
        int|array|null $ignorerSlotId = null,
        ?int $effectif = null,
        ?Schedule $schedule = null,
        string $parite = 'toutes',
        ?array $semaines = null,
        ?int $sousGroupeId = null,
        ?array $equipementsRequis = null,
        ?array $coEnseignantsIds = null,
        ?int $matiereId = null,
    ): array {
        // Le planning est rechargé uniquement si l'appelant ne le fournit
        // pas (les boucles chaudes le passent — pas de N+1 supplémentaire).
        $schedule ??= Schedule::with('groupe')->find($scheduleId);

        $effectifCalcule = $this->resoudreEffectif($schedule, $effectif, $sousGroupeId);

        $tousEnseignants = array_values(array_filter(array_unique(array_merge(
            $teacherId !== null ? [$teacherId] : [],
            $coEnseignantsIds ?? [],
        ))));

        $conflitsEnseignants = [];
        foreach ($tousEnseignants as $ensId) {
            $conflitsEnseignants = array_merge(
                $conflitsEnseignants,
                $this->conflitsEnseignant($jour, $debut, $fin, $ensId, $ignorerSlotId, $parite, $semaines)
            );
        }

        return array_merge(
            $this->conflitsGroupe($scheduleId, $jour, $debut, $fin, $ignorerSlotId, $parite, $semaines, $sousGroupeId),
            $conflitsEnseignants,
            $this->conflitsSalle($jour, $debut, $fin, $roomId, $ignorerSlotId, $parite, $semaines),
            $this->conflitsCapacite($roomId, $effectifCalcule),
            $this->conflitsEquipements($roomId, $equipementsRequis),
            $this->conflitsQualifications($tousEnseignants, $matiereId),
            $schedule !== null
                ? $this->conflitsDisponibilites($schedule, $jour, $debut, $fin, $teacherId, $roomId)
                : [],
            $this->conflitsPauses($debut, $fin, $jour, $schedule?->groupe?->faculte_id),
            $this->conflitsHorairesSalle($roomId, $debut, $fin),
        );
    }

    /**
     * Pauses institutionnelles obligatoires : interdiction de chevaucher une pause.
     *
     * @return array<int, string>
     */
    public function conflitsPauses(string $debut, string $fin, ?string $jour = null, ?int $faculteId = null): array
    {
        return app(ScheduleCalendarService::class)->verifierPausesObligatoires($debut, $fin, $jour, $faculteId);
    }

    /**
     * Horaires d'ouverture de la salle / bâtiment.
     *
     * @return array<int, string>
     */
    public function conflitsHorairesSalle(?int $roomId, string $debut, string $fin): array
    {
        return app(ScheduleCalendarService::class)->verifierHorairesSalle($roomId, $debut, $fin);
    }

    /**
     * Adéquation des équipements requis avec ceux disponibles dans la salle.
     *
     * @return array<int, string>
     */
    public function conflitsEquipements(?int $roomId, ?array $equipementsRequis): array
    {
        if ($roomId === null || empty($equipementsRequis)) {
            return [];
        }

        $salle = Room::find($roomId);
        if ($salle === null) {
            return [];
        }

        $disponibles = (array) ($salle->equipements_fixes ?? []);
        $manquants = array_diff($equipementsRequis, $disponibles);

        if (! empty($manquants)) {
            $liste = implode(', ', $manquants);

            return ["Équipements requis manquants dans la salle {$salle->code} : {$liste}."];
        }

        return [];
    }

    /**
     * Vérifie si tous les enseignants affectés possèdent les qualifications pour la matière.
     *
     * @param  array<int, int>  $teacherIds
     * @return array<int, string>
     */
    public function conflitsQualifications(array $teacherIds, ?int $matiereId): array
    {
        if (empty($teacherIds) || $matiereId === null) {
            return [];
        }

        $matiere = Matiere::find($matiereId);
        if ($matiere === null) {
            return [];
        }

        $erreurs = [];
        $enseignants = Teacher::whereIn('id', $teacherIds)->get();

        foreach ($enseignants as $ens) {
            if (! $ens->estQualifiePour($matiere->code)) {
                $erreurs[] = "L'enseignant {$ens->nomComplet()} n'est pas qualifié pour dispenser la matière {$matiere->code} ({$matiere->nom}).";
            }
        }

        return $erreurs;
    }

    public function resoudreEffectif(?Schedule $schedule, ?int $effectif, ?int $sousGroupeId): ?int
    {
        if ($sousGroupeId !== null) {
            $sg = SousGroupe::find($sousGroupeId);
            if ($sg !== null && $sg->capacite !== null) {
                return (int) $sg->capacite;
            }
        }

        if ($effectif !== null) {
            return $effectif;
        }

        $etudiantsCount = $schedule?->groupe?->etudiants()->count() ?? 0;
        if ($etudiantsCount > 0) {
            return $etudiantsCount;
        }

        // Si aucun étudiant encore inscrit dans le groupe, repli sur la capacité prévisionnelle
        if ($schedule?->groupe?->capacite !== null) {
            return (int) $schedule->groupe->capacite;
        }

        return 0;
    }

    /**
     * PHASE A — indisponibilités déclarées (contrainte dure) : enseignant,
     * salle ou groupe concerné, portée année du planning (ou plage globale),
     * fenêtre effective recouvrant la période du planning.
     *
     * Filtrage SQL par jour + ressources puis chevauchement horaire en PHP
     * (même convention que conflitsGroupe/Salle — sémantique identique).
     *
     * @return array<int, string>
     */
    public function conflitsDisponibilites(
        Schedule $schedule,
        string $jour,
        string $debut,
        string $fin,
        ?int $teacherId = null,
        ?int $roomId = null,
    ): array {
        $plages = ScheduleAvailability::query()
            ->indisponibles()
            ->where('day_of_week', $jour)
            ->where(function (Builder $q) use ($schedule) {
                $q->whereNull('annee_universitaire_id');
                if ($schedule->annee_universitaire_id !== null) {
                    $q->orWhere('annee_universitaire_id', $schedule->annee_universitaire_id);
                }
            })
            ->where(function (Builder $q) use ($teacherId, $roomId, $schedule) {
                $q->where('group_id', $schedule->groupe_id);
                if ($teacherId !== null) {
                    $q->orWhere('teacher_id', $teacherId);
                }
                if ($roomId !== null) {
                    $q->orWhere('room_id', $roomId);
                }
            })
            ->with(['teacher', 'room', 'group'])
            ->get()
            ->filter(fn (ScheduleAvailability $plage) => $plage->chevauche($debut, $fin)
                && $plage->couvrePeriode(
                    $schedule->starts_at?->format('Y-m-d'),
                    $schedule->ends_at?->format('Y-m-d'),
                ));

        return $plages->map(function (ScheduleAvailability $plage) use ($teacherId, $roomId) {
            $motif = filled($plage->reason) ? " — motif : {$plage->reason}" : '';

            $ressource = match (true) {
                $plage->teacher_id !== null && $plage->teacher_id === $teacherId => 'Enseignant déclaré indisponible',
                $plage->room_id !== null && $plage->room_id === $roomId => 'Salle déclarée indisponible',
                default => 'Groupe déclaré indisponible',
            };

            return "{$ressource} ({$plage->plageHumaine()}){$motif}.";
        })->values()->all();
    }

    /**
     * Capacité (§12) : l'effectif du groupe ne doit pas dépasser la
     * capacité de la salle (salle sans capacité = pas de contrôle).
     *
     * @return array<int, string>
     */
    public function conflitsCapacite(?int $roomId, ?int $effectif): array
    {
        if ($roomId === null || $effectif === null) {
            return [];
        }

        $salle = Room::find($roomId);

        if ($salle === null || $salle->capacite === null) {
            return [];
        }

        if ($effectif > (int) $salle->capacite) {
            return ["Capacité dépassée : {$effectif} étudiants pour {$salle->capacite} places (salle {$salle->code})."];
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    public function conflitsGroupe(
        int $scheduleId,
        string $jour,
        string $debut,
        string $fin,
        int|array|null $ignorerSlotId = null,
        string $parite = 'toutes',
        ?array $semaines = null,
        ?int $sousGroupeId = null,
    ): array {
        $conflits = $this->creneauxActifs()
            ->where('schedule_id', $scheduleId)
            ->where('day_of_week', $jour)
            ->when($ignorerSlotId !== null, fn ($q) => is_array($ignorerSlotId) ? $q->whereNotIn('id', $ignorerSlotId) : $q->where('id', '!=', $ignorerSlotId))
            ->get()
            ->filter(function (ScheduleSlot $slot) use ($debut, $fin, $parite, $semaines, $sousGroupeId) {
                if (! $slot->chevauche($debut, $fin)) {
                    return false;
                }

                // Parité disjointe = aucun conflit
                if (! $slot->conflitSemainesAvec($parite, $semaines)) {
                    return false;
                }

                // Sous-groupes distincts = pas de conflit
                if ($sousGroupeId !== null && $slot->sous_groupe_id !== null && $slot->sous_groupe_id !== $sousGroupeId) {
                    return false;
                }

                return true;
            });

        return $conflits->map(fn (ScheduleSlot $slot) => "Groupe occupé par « {$slot->matiere->nom} » ({$slot->start_time}–{$slot->end_time}).")->all();
    }

    /**
     * Conflits enseignant GLOBAUX (tous plannings confondus — un enseignant
     * ne peut pas être réservé deux fois sur la même plage, même dans des
     * plannings de groupes ou facultés différents).
     *
     * @return array<int, string>
     */
    public function conflitsEnseignant(
        string $jour,
        string $debut,
        string $fin,
        ?int $teacherId,
        int|array|null $ignorerSlotId = null,
        string $parite = 'toutes',
        ?array $semaines = null,
    ): array {
        if ($teacherId === null) {
            return [];
        }

        $conflits = $this->creneauxActifs()
            ->where(function (Builder $q) use ($teacherId) {
                $q->where('teacher_id', $teacherId)
                    ->orWhereHas('teachersPivot', fn ($sub) => $sub->where('teacher_id', $teacherId));
            })
            ->where('day_of_week', $jour)
            ->when($ignorerSlotId !== null, fn ($q) => is_array($ignorerSlotId) ? $q->whereNotIn('id', $ignorerSlotId) : $q->where('id', '!=', $ignorerSlotId))
            ->with(['schedule.groupe', 'matiere'])
            ->get()
            ->filter(function (ScheduleSlot $slot) use ($debut, $fin, $parite, $semaines) {
                return $slot->chevauche($debut, $fin) && $slot->conflitSemainesAvec($parite, $semaines);
            });

        return $conflits->map(fn (ScheduleSlot $slot) => "Enseignant occupé ({$slot->schedule->groupe->code} : {$slot->matiere->nom}, {$slot->start_time}–{$slot->end_time}).")->all();
    }

    /**
     * @return array<int, string>
     */
    public function conflitsSalle(
        string $jour,
        string $debut,
        string $fin,
        ?int $roomId,
        int|array|null $ignorerSlotId = null,
        string $parite = 'toutes',
        ?array $semaines = null,
    ): array {
        if ($roomId === null) {
            return [];
        }

        $conflits = $this->creneauxActifs()
            ->where('room_id', $roomId)
            ->where('day_of_week', $jour)
            ->when($ignorerSlotId !== null, fn ($q) => is_array($ignorerSlotId) ? $q->whereNotIn('id', $ignorerSlotId) : $q->where('id', '!=', $ignorerSlotId))
            ->with(['schedule.groupe', 'matiere'])
            ->get()
            ->filter(function (ScheduleSlot $slot) use ($debut, $fin, $parite, $semaines) {
                return $slot->chevauche($debut, $fin) && $slot->conflitSemainesAvec($parite, $semaines);
            });

        return $conflits->map(fn (ScheduleSlot $slot) => "Salle occupée ({$slot->schedule->groupe->code} : {$slot->matiere->nom}, {$slot->start_time}–{$slot->end_time}).")->all();
    }

    /**
     * @return Collection<int, ScheduleSlot>
     */
    public function creneauxEnConflit(
        int $scheduleId,
        string $jour,
        string $debut,
        string $fin,
        int|array|null $ignorerSlotId = null,
    ): Collection {
        return $this->creneauxActifs()
            ->where('schedule_id', $scheduleId)
            ->where('day_of_week', $jour)
            ->when($ignorerSlotId !== null, fn ($q) => is_array($ignorerSlotId) ? $q->whereNotIn('id', $ignorerSlotId) : $q->where('id', '!=', $ignorerSlotId))
            ->get()
            ->filter(fn (ScheduleSlot $slot) => $slot->chevauche($debut, $fin))
            ->values();
    }

    private function creneauxActifs(): Builder
    {
        return ScheduleSlot::where('status', '!=', 'cancelled');
    }
}
