<?php

namespace App\Modules\Attendance\Models;

use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * État d'un appel de présence (séance × date) : `en_cours` puis `cloture`
 * explicite avec snapshot des compteurs. L'absence de ligne = appel non
 * commencé (jamais confondu avec un appel vide ou des absences).
 */
class SeanceAppel extends Model
{
    protected $table = 'seance_appels';

    public const STATUTS = ['en_cours', 'cloture'];

    protected $fillable = [
        'schedule_slot_id', 'date', 'statut', 'teacher_id',
        'presents', 'absents', 'retards', 'non_renseignes', 'cloture_le',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['date' => 'date', 'cloture_le' => 'datetime'];
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(ScheduleSlot::class, 'schedule_slot_id');
    }

    public function enseignant(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    public function estCloture(): bool
    {
        return $this->statut === 'cloture';
    }
}
