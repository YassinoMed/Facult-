<?php

namespace App\Modules\Schedule\Models;

use App\Models\Faculte;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Grille horaire standardisée (créneaux types et pauses institutionnelles).
 */
class TimeSlot extends Model
{
    protected $table = 'time_slots';

    public const TYPES = ['course', 'break', 'lunch', 'exam'];

    protected $fillable = [
        'faculte_id',
        'code',
        'nom',
        'day_of_week',
        'start_time',
        'end_time',
        'type',
        'is_mandatory_break',
        'ordre',
        'actif',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_mandatory_break' => 'boolean',
            'actif' => 'boolean',
            'ordre' => 'integer',
        ];
    }

    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class);
    }

    public function dureeMinutes(): int
    {
        $debut = Carbon::createFromFormat('H:i:s', strlen($this->start_time) === 5 ? $this->start_time.':00' : $this->start_time);
        $fin = Carbon::createFromFormat('H:i:s', strlen($this->end_time) === 5 ? $this->end_time.':00' : $this->end_time);

        return (int) abs($debut->diffInMinutes($fin));
    }

    public function chevauche(string $debut, string $fin): bool
    {
        return $this->start_time < $fin && $debut < $this->end_time;
    }

    public function estPause(): bool
    {
        return $this->is_mandatory_break || in_array($this->type, ['break', 'lunch'], true);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActif(Builder $query): Builder
    {
        return $query->where('actif', true);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeCours(Builder $query): Builder
    {
        return $query->where('type', 'course')->where('is_mandatory_break', false);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePauses(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('is_mandatory_break', true)
                ->orWhereIn('type', ['break', 'lunch']);
        });
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePourFaculte(Builder $query, ?int $faculteId): Builder
    {
        return $query->where(function (Builder $q) use ($faculteId) {
            $q->whereNull('faculte_id');
            if ($faculteId !== null) {
                $q->orWhere('faculte_id', $faculteId);
            }
        });
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePourJour(Builder $query, ?string $jour): Builder
    {
        if ($jour === null) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($jour) {
            $q->whereNull('day_of_week')
                ->orWhere('day_of_week', $jour);
        });
    }
}
