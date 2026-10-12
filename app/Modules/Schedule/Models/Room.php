<?php

namespace App\Modules\Schedule\Models;

use App\Models\Faculte;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Module Schedule : salle.
 */
class Room extends Model
{
    protected $table = 'rooms';

    protected $fillable = ['code', 'nom', 'capacite', 'min_surveillants', 'ratio_surveillants', 'actif', 'campus', 'batiment', 'etage', 'faculte_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['actif' => 'boolean', 'capacite' => 'integer', 'min_surveillants' => 'integer', 'ratio_surveillants' => 'integer'];
    }

    /**
     * @return HasMany<ScheduleSlot, $this>
     */
    public function slots(): HasMany
    {
        return $this->hasMany(ScheduleSlot::class, 'room_id');
    }

    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class);
    }

    public function localisation(): string
    {
        return implode(' · ', array_filter([$this->campus, $this->batiment, $this->etage])) ?: '—';
    }
}
