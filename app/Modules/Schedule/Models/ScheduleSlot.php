<?php

namespace App\Modules\Schedule\Models;

use App\Models\Matiere;
use App\Modules\Attendance\Models\TeacherPresence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Module Schedule : créneau hebdomadaire récurrent. Matière = modèle
 * `Matiere` du cœur réutilisé (aucune table course créée).
 */
class ScheduleSlot extends Model
{
    protected $table = 'schedule_slots';

    public const JOURS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    public const TYPES = ['course', 'td', 'tp', 'exam', 'meeting', 'workshop', 'other'];

    public const STATUTS = ['scheduled', 'cancelled', 'modified', 'completed'];

    protected $fillable = [
        'schedule_id', 'matiere_id', 'teacher_id', 'room_id',
        'day_of_week', 'start_time', 'end_time', 'type', 'status', 'notes',
    ];

    /**
     * @return BelongsTo<Schedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    /**
     * @return BelongsTo<Matiere, $this>
     */
    public function matiere(): BelongsTo
    {
        return $this->belongsTo(Matiere::class);
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return HasMany<ScheduleException, $this>
     */
    public function exceptions(): HasMany
    {
        return $this->hasMany(ScheduleException::class, 'schedule_slot_id');
    }

    /**
     * @return HasMany<ScheduleChange, $this>
     */
    public function changes(): HasMany
    {
        return $this->hasMany(ScheduleChange::class, 'schedule_slot_id')->latest();
    }

    /**
     * @return HasMany<TeacherPresence, $this>
     */
    public function presences(): HasMany
    {
        return $this->hasMany(TeacherPresence::class, 'schedule_slot_id');
    }

    public function chevauche(string $debut, string $fin): bool
    {
        return $this->start_time < $fin && $debut < $this->end_time;
    }
}
