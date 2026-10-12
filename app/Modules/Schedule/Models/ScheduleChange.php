<?php

namespace App\Modules\Schedule\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module Schedule : historique immuable (avant/après JSON, sans PII).
 */
class ScheduleChange extends Model
{
    protected $table = 'schedule_changes';

    public const TYPES = [
        'created', 'updated', 'room_changed', 'time_changed',
        'teacher_changed', 'cancelled', 'restored', 'deleted', 'exception',
    ];

    protected $fillable = [
        'schedule_slot_id', 'changed_by', 'change_type',
        'old_values', 'new_values', 'reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array'];
    }

    /**
     * @return BelongsTo<ScheduleSlot, $this>
     */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(ScheduleSlot::class, 'schedule_slot_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
