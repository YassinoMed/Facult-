<?php

namespace App\Modules\Schedule\Services;

use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\ScheduleSlot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Détection des conflits (§8) : réutilisable en création, modification,
 * déplacement et exception. Chevauchement strict (fin == début = OK).
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
        ?int $ignorerSlotId = null,
        ?int $effectif = null,
    ): array {
        return array_merge(
            $this->conflitsGroupe($scheduleId, $jour, $debut, $fin, $ignorerSlotId),
            $this->conflitsEnseignant($jour, $debut, $fin, $teacherId, $ignorerSlotId),
            $this->conflitsSalle($jour, $debut, $fin, $roomId, $ignorerSlotId),
            $this->conflitsCapacite($roomId, $effectif),
        );
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
    public function conflitsGroupe(int $scheduleId, string $jour, string $debut, string $fin, ?int $ignorerSlotId = null): array
    {
        $conflits = $this->creneauxActifs()
            ->where('schedule_id', $scheduleId)
            ->where('day_of_week', $jour)
            ->when($ignorerSlotId !== null, fn ($q) => $q->where('id', '!=', $ignorerSlotId))
            ->get()
            ->filter(fn (ScheduleSlot $slot) => $slot->chevauche($debut, $fin));

        return $conflits->map(fn (ScheduleSlot $slot) => "Groupe occupé par « {$slot->matiere->nom} » ({$slot->start_time}–{$slot->end_time}).")->all();
    }

    /**
     * Conflits enseignant GLOBAUX (tous plannings confondus — un enseignant
     * ne peut pas être réservé deux fois sur la même plage, même dans des
     * plannings de groupes ou facultés différents).
     *
     * @return array<int, string>
     */
    public function conflitsEnseignant(string $jour, string $debut, string $fin, ?int $teacherId, ?int $ignorerSlotId = null): array
    {
        if ($teacherId === null) {
            return [];
        }

        $conflits = $this->creneauxActifs()
            ->where('teacher_id', $teacherId)
            ->where('day_of_week', $jour)
            ->when($ignorerSlotId !== null, fn ($q) => $q->where('id', '!=', $ignorerSlotId))
            ->with(['schedule.groupe', 'matiere'])
            ->get()
            ->filter(fn (ScheduleSlot $slot) => $slot->chevauche($debut, $fin));

        return $conflits->map(fn (ScheduleSlot $slot) => "Enseignant occupé ({$slot->schedule->groupe->code} : {$slot->matiere->nom}, {$slot->start_time}–{$slot->end_time}).")->all();
    }

    /**
     * @return array<int, string>
     */
    public function conflitsSalle(string $jour, string $debut, string $fin, ?int $roomId, ?int $ignorerSlotId = null): array
    {
        if ($roomId === null) {
            return [];
        }

        $conflits = $this->creneauxActifs()
            ->where('room_id', $roomId)
            ->where('day_of_week', $jour)
            ->when($ignorerSlotId !== null, fn ($q) => $q->where('id', '!=', $ignorerSlotId))
            ->with(['schedule.groupe', 'matiere'])
            ->get()
            ->filter(fn (ScheduleSlot $slot) => $slot->chevauche($debut, $fin));

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
        ?int $ignorerSlotId = null,
    ): Collection {
        return $this->creneauxActifs()
            ->where('schedule_id', $scheduleId)
            ->where('day_of_week', $jour)
            ->when($ignorerSlotId !== null, fn ($q) => $q->where('id', '!=', $ignorerSlotId))
            ->get()
            ->filter(fn (ScheduleSlot $slot) => $slot->chevauche($debut, $fin))
            ->values();
    }

    private function creneauxActifs(): Builder
    {
        return ScheduleSlot::where('status', '!=', 'cancelled');
    }
}
