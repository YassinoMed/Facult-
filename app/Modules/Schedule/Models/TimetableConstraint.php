<?php

namespace App\Modules\Schedule\Models;

use App\Models\Faculte;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PHASE 3 (Smart Timetables) — Registre configurable des contraintes
 * d'emploi du temps inspiré d'aSc TimeTables et adapté au LMD.
 *
 * Contraintes DURES (Hard) : bloquantes pour la publication.
 * Contraintes SOUPLES (Soft) : pondérées (poids 1-100) pour optimiser la qualité.
 */
class TimetableConstraint extends Model
{
    protected $table = 'timetable_constraints';

    public const CATEGORIE_HARD = 'hard';

    public const CATEGORIE_SOFT = 'soft';

    public const CATEGORIES = [
        self::CATEGORIE_HARD,
        self::CATEGORIE_SOFT,
    ];

    // --- CODES CONTRAINTES DURES (HARD) ---
    public const HARD_TEACHER_CONFLICT = 'hard_teacher_conflict';

    public const HARD_ROOM_CONFLICT = 'hard_room_conflict';

    public const HARD_GROUP_CONFLICT = 'hard_group_conflict';

    public const HARD_ROOM_CAPACITY = 'hard_room_capacity';

    public const HARD_TEACHER_UNAVAILABLE = 'hard_teacher_unavailable';

    public const HARD_ROOM_UNAVAILABLE = 'hard_room_unavailable';

    public const HARD_MISSING_EQUIPMENT = 'hard_missing_equipment';

    public const HARD_TEACHER_QUALIFICATION = 'hard_teacher_qualification';

    public const HARD_ORPHAN_SLOT = 'hard_orphan_slot';

    public const HARD_INSTITUTIONAL_PAUSE = 'hard_institutional_pause';

    public const HARD_ROOM_OPENING_HOURS = 'hard_room_opening_hours';

    // --- CODES CONTRAINTES SOUPLES (SOFT) ---
    public const SOFT_STUDENT_IDLE_GAPS = 'soft_student_idle_gaps';

    public const SOFT_TEACHER_IDLE_GAPS = 'soft_teacher_idle_gaps';

    public const SOFT_MAX_CONSECUTIVE_HOURS = 'soft_max_consecutive_hours';

    public const SOFT_MAX_DAILY_HOURS = 'soft_max_daily_hours';

    public const SOFT_TEACHER_PREFERENCE = 'soft_teacher_preference';

    public const SOFT_BUILDING_TRANSIT = 'soft_building_transit';

    public const SOFT_ROOM_OVERSIZED = 'soft_room_oversized';

    public const SOFT_DAILY_EQUILIBRIUM = 'soft_daily_equilibrium';

    protected $fillable = [
        'faculte_id',
        'code',
        'nom',
        'categorie',
        'description',
        'actif',
        'poids',
        'parametres',
    ];

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
            'poids' => 'integer',
            'parametres' => 'array',
        ];
    }

    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class);
    }

    public function scopeActif(Builder $query): Builder
    {
        return $query->where('actif', true);
    }

    public function scopeHard(Builder $query): Builder
    {
        return $query->where('categorie', self::CATEGORIE_HARD);
    }

    public function scopeSoft(Builder $query): Builder
    {
        return $query->where('categorie', self::CATEGORIE_SOFT);
    }

    public function scopePourFaculte(Builder $query, ?int $faculteId = null): Builder
    {
        if ($faculteId === null) {
            return $query->whereNull('faculte_id');
        }

        return $query->where(function (Builder $q) use ($faculteId) {
            $q->where('faculte_id', $faculteId)
                ->orWhereNull('faculte_id');
        });
    }

    public function estDure(): bool
    {
        return $this->categorie === self::CATEGORIE_HARD;
    }

    public function estSouple(): bool
    {
        return $this->categorie === self::CATEGORIE_SOFT;
    }
}
