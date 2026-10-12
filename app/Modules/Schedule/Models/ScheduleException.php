<?php

namespace App\Modules\Schedule\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module Schedule : modification ponctuelle d'un créneau, une date.
 */
class ScheduleException extends Model
{
    protected $table = 'schedule_exceptions';

    public const STATUTS = ['scheduled', 'cancelled', 'modified', 'moved'];

    protected $fillable = [
        'schedule_slot_id', 'exception_date',
        'new_start_time', 'new_end_time',
        'new_room_id', 'new_teacher_id',
        'status', 'reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['exception_date' => 'date'];
    }

    /**
     * @return BelongsTo<ScheduleSlot, $this>
     */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(ScheduleSlot::class, 'schedule_slot_id');
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function nouvelleSalle(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'new_room_id');
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function nouvelEnseignant(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'new_teacher_id');
    }
}
