<?php

namespace App\Modules\Schedule\Services;

use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleChange;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\ScheduleSlotTeacher;
use App\Modules\Schedule\Models\ScheduleVersion;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Service de gestion des versions immuables, snapshots de publication,
 * comparaison structurelle (diff) et rollback sécurisé de plannings.
 */
class ScheduleVersioningService
{
    public function __construct(
        protected ScheduleConflictService $conflictService,
        protected ScheduleConstraintChecker $constraintChecker
    ) {}

    /**
     * Publie formellement un emploi du temps en créant un instantané immuable (version).
     *
     * @throws DomainException
     */
    public function creerVersionPubliee(
        Schedule $schedule,
        ?int $userId = null,
        ?string $note = null
    ): ScheduleVersion {
        $slots = $schedule->slots()->with(['matiere', 'teacher', 'room', 'teachersPivot'])->get();

        if ($slots->isEmpty()) {
            throw new DomainException('Impossible de publier un emploi du temps sans aucun créneau.');
        }

        // Vérification des conflits durs bloquants avant publication
        $audit = $this->constraintChecker->auditerPlanning($schedule);
        if (! $audit->estValide()) {
            $msg = implode(' | ', array_column($audit->violationsDures(), 'message'));
            throw new DomainException("Publication impossible : le planning comporte des violations bloquantes : {$msg}");
        }

        return DB::transaction(function () use ($schedule, $slots, $audit, $userId, $note) {
            $dernierNumero = (int) ScheduleVersion::where('schedule_id', $schedule->id)->max('numero_version');
            $nouveauNumero = $dernierNumero + 1;

            // Archiver les anciennes versions publiées
            ScheduleVersion::where('schedule_id', $schedule->id)
                ->where('statut', ScheduleVersion::STATUT_PUBLIEE)
                ->update(['statut' => ScheduleVersion::STATUT_ARCHIVEE]);

            // Sérialisation immuable des créneaux
            $snapshotData = $this->serialiserSlots($slots);

            $version = ScheduleVersion::create([
                'schedule_id' => $schedule->id,
                'numero_version' => $nouveauNumero,
                'code_version' => "V{$nouveauNumero}.0",
                'statut' => ScheduleVersion::STATUT_PUBLIEE,
                'snapshot_slots_json' => $snapshotData,
                'note_version' => $note ?? "Publication officielle version V{$nouveauNumero}.0",
                'nb_slots' => $slots->count(),
                'score_qualite' => $audit->scoreQualite(),
                'published_by' => $userId,
                'published_at' => now(),
            ]);

            // Passage du planning au statut publié
            $schedule->update([
                'statut' => 'publie',
                'publie_a' => now(),
            ]);

            return $version;
        });
    }

    /**
     * Capture un instantané d'étape sans changer le statut du planning (snapshot brouillon).
     */
    public function capturerSnapshotBrouillon(
        Schedule $schedule,
        ?int $userId = null,
        ?string $note = null
    ): ScheduleVersion {
        $slots = $schedule->slots()->with(['matiere', 'teacher', 'room', 'teachersPivot'])->get();

        $dernierNumero = (int) ScheduleVersion::where('schedule_id', $schedule->id)->max('numero_version');
        $nouveauNumero = $dernierNumero + 1;

        $snapshotData = $this->serialiserSlots($slots);

        return ScheduleVersion::create([
            'schedule_id' => $schedule->id,
            'numero_version' => $nouveauNumero,
            'code_version' => "Snapshot-{$nouveauNumero}",
            'statut' => ScheduleVersion::STATUT_BROUILLON_SNAPSHOT,
            'snapshot_slots_json' => $snapshotData,
            'note_version' => $note ?? "Instantané de travail #{$nouveauNumero}",
            'nb_slots' => $slots->count(),
            'score_qualite' => null,
            'published_by' => $userId,
            'published_at' => null,
        ]);
    }

    /**
     * Compare deux versions immuables et produit un diff structurel précis.
     *
     * @return array{
     *     version_a: string,
     *     version_b: string,
     *     ajoutes: array<int, array<string, mixed>>,
     *     supprimes: array<int, array<string, mixed>>,
     *     modifies: array<int, array<string, mixed>>,
     *     identiques_count: int,
     *     resume: string
     * }
     */
    public function comparerVersions(ScheduleVersion $vA, ScheduleVersion $vB): array
    {
        $slotsA = $vA->snapshot_slots_json ?? [];
        $slotsB = $vB->snapshot_slots_json ?? [];

        // Indexation par clé d'identification pédagogique : matiere_id + type + parite
        // ou par index de comparaison
        $ajoutes = [];
        $supprimes = [];
        $modifies = [];
        $identiquesCount = 0;

        // Associons les créneaux par matière et type
        $mapA = [];
        foreach ($slotsA as $idx => $sA) {
            $cle = ($sA['matiere_code'] ?? $sA['matiere_id']).'_'.($sA['type'] ?? 'course').'_'.($sA['semaine_parite'] ?? 'toutes');
            $mapA[$cle][] = $sA;
        }

        $mapB = [];
        foreach ($slotsB as $idx => $sB) {
            $cle = ($sB['matiere_code'] ?? $sB['matiere_id']).'_'.($sB['type'] ?? 'course').'_'.($sB['semaine_parite'] ?? 'toutes');
            $mapB[$cle][] = $sB;
        }

        $toutesCles = array_unique(array_merge(array_keys($mapA), array_keys($mapB)));

        foreach ($toutesCles as $cle) {
            $listeA = $mapA[$cle] ?? [];
            $listeB = $mapB[$cle] ?? [];

            $maxCount = max(count($listeA), count($listeB));

            for ($i = 0; $i < $maxCount; $i++) {
                $elA = $listeA[$i] ?? null;
                $elB = $listeB[$i] ?? null;

                if ($elA === null && $elB !== null) {
                    $ajoutes[] = $elB;
                } elseif ($elA !== null && $elB === null) {
                    $supprimes[] = $elA;
                } elseif ($elA !== null && $elB !== null) {
                    $diffs = $this->calculerDiffSlot($elA, $elB);
                    if (! empty($diffs)) {
                        $modifies[] = [
                            'avant' => $elA,
                            'apres' => $elB,
                            'changements' => $diffs,
                        ];
                    } else {
                        $identiquesCount++;
                    }
                }
            }
        }

        $nbAj = count($ajoutes);
        $nbSup = count($supprimes);
        $nbMod = count($modifies);

        $resume = "Comparaison {$vA->code_version} → {$vB->code_version} : +{$nbAj} créneaux, -{$nbSup} créneaux, {$nbMod} modifiés, {$identiquesCount} identiques.";

        return [
            'version_a' => $vA->code_version,
            'version_b' => $vB->code_version,
            'ajoutes' => $ajoutes,
            'supprimes' => $supprimes,
            'modifies' => $modifies,
            'identiques_count' => $identiquesCount,
            'resume' => $resume,
        ];
    }

    /**
     * Restaure les créneaux d'un planning depuis une version immuable.
     * RÈGLE DE SÉCURITÉ : Le planning restauré repasse TOUJOURS en statut 'brouillon'.
     *
     * @throws DomainException
     */
    public function restaurerVersion(
        ScheduleVersion $version,
        ?int $userId = null,
        ?string $motif = null
    ): Schedule {
        $schedule = $version->schedule;

        if ($schedule->estVerrouille()) {
            throw new DomainException('Impossible de restaurer une version sur un planning verrouillé ou archivé.');
        }

        $slotsSnapshot = $version->snapshot_slots_json ?? [];

        return DB::transaction(function () use ($schedule, $version, $slotsSnapshot, $userId, $motif) {
            // Suppression des créneaux existants actuels
            $schedule->slots()->delete();

            // Réinsertion des créneaux depuis le snapshot
            foreach ($slotsSnapshot as $data) {
                $nouveauSlot = ScheduleSlot::create([
                    'schedule_id' => $schedule->id,
                    'matiere_id' => $data['matiere_id'],
                    'enseignement_id' => $data['enseignement_id'] ?? null,
                    'sous_groupe_id' => $data['sous_groupe_id'] ?? null,
                    'teacher_id' => $data['teacher_id'] ?? null,
                    'room_id' => $data['room_id'] ?? null,
                    'day_of_week' => $data['day_of_week'],
                    'start_time' => $data['start_time'],
                    'end_time' => $data['end_time'],
                    'semaine_parite' => $data['semaine_parite'] ?? 'toutes',
                    'semaines_specifiques' => $data['semaines_specifiques'] ?? null,
                    'type' => $data['type'] ?? 'course',
                    'status' => 'scheduled',
                    'equipements_requis' => $data['equipements_requis'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ]);

                // Réassociation des co-enseignants
                if (! empty($data['co_enseignants'])) {
                    foreach ($data['co_enseignants'] as $co) {
                        ScheduleSlotTeacher::create([
                            'schedule_slot_id' => $nouveauSlot->id,
                            'teacher_id' => $co['teacher_id'],
                            'role' => $co['role'] ?? 'co_enseignant',
                            'volume_heures' => $co['volume_heures'] ?? null,
                        ]);
                    }
                }

                // Historisation de la restauration
                ScheduleChange::create([
                    'schedule_slot_id' => $nouveauSlot->id,
                    'changed_by' => $userId,
                    'change_type' => 'restored',
                    'old_values' => null,
                    'new_values' => [
                        'restaure_depuis_version' => $version->code_version,
                        'numero_version' => $version->numero_version,
                    ],
                    'reason' => $motif ?? "Restauration rollback vers version {$version->code_version}",
                ]);
            }

            // Repasse formellement le planning en brouillon pour validation
            $schedule->update([
                'statut' => 'brouillon',
                'publie_a' => null,
            ]);

            return $schedule->fresh(['slots.matiere', 'slots.room', 'slots.teacher']);
        });
    }

    /**
     * @param  iterable<ScheduleSlot>  $slots
     * @return array<int, array<string, mixed>>
     */
    protected function serialiserSlots(iterable $slots): array
    {
        $snapshot = [];
        foreach ($slots as $slot) {
            $coEns = [];
            foreach ($slot->teachersPivot as $tp) {
                $coEns[] = [
                    'teacher_id' => $tp->teacher_id,
                    'role' => $tp->role,
                    'volume_heures' => $tp->volume_heures,
                ];
            }

            $snapshot[] = [
                'slot_id' => $slot->id,
                'matiere_id' => $slot->matiere_id,
                'matiere_code' => $slot->matiere?->code,
                'matiere_nom' => $slot->matiere?->nom,
                'enseignement_id' => $slot->enseignement_id,
                'sous_groupe_id' => $slot->sous_groupe_id,
                'teacher_id' => $slot->teacher_id,
                'teacher_nom' => $slot->teacher?->nom_complet ?? $slot->teacher?->nom,
                'room_id' => $slot->room_id,
                'room_nom' => $slot->room?->nom,
                'day_of_week' => $slot->day_of_week,
                'start_time' => $slot->start_time,
                'end_time' => $slot->end_time,
                'semaine_parite' => $slot->semaine_parite,
                'semaines_specifiques' => $slot->semaines_specifiques,
                'type' => $slot->type,
                'equipements_requis' => $slot->equipements_requis,
                'notes' => $slot->notes,
                'co_enseignants' => $coEns,
            ];
        }

        return $snapshot;
    }

    /**
     * Calcule la liste des attributs modifiés entre deux créneaux sérialisés.
     *
     * @return array<string, array{avant: mixed, apres: mixed}>
     */
    protected function calculerDiffSlot(array $sA, array $sB): array
    {
        $diffs = [];
        $clesAComparer = ['day_of_week', 'start_time', 'end_time', 'room_id', 'teacher_id', 'type', 'semaine_parite'];

        foreach ($clesAComparer as $cle) {
            $valA = $sA[$cle] ?? null;
            $valB = $sB[$cle] ?? null;

            if ($valA !== $valB) {
                $diffs[$cle] = [
                    'avant' => $valA,
                    'apres' => $valB,
                ];
            }
        }

        return $diffs;
    }
}
