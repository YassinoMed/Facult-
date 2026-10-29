<?php

namespace App\Modules\Schedule\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot co-enseignement et multi-enseignants sur un créneau d'emploi du temps.
 */
class ScheduleSlotTeacher extends Model
{
    protected $table = 'schedule_slot_teachers';

    public const ROLES = ['principal', 'coenseignant', 'assistant'];

    protected $fillable = [
        'schedule_slot_id',
        'teacher_id',
        'role',
        'volume_heures',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'volume_heures' => 'float',
        ];
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(ScheduleSlot::class, 'schedule_slot_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePrincipal(Builder $query): Builder
    {
        return $query->where('role', 'principal');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeCoenseignants(Builder $query): Builder
    {
        return $query->where('role', '!=', 'principal');
    }
}
