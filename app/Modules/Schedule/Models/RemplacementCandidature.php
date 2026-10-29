<?php

namespace App\Modules\Schedule\Models;

use App\Models\Matiere;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Candidature de remplacement pédagogique (LOT 09) : un enseignant se
 * déclare disponible pour assurer les cours d'une matière en cas
 * d'absence du titulaire. Le volontariat ne mute JAMAIS l'EDT —
 * seule l'acceptation par l'administration déclenche le remplacement
 * transactionnel existant (`ScheduleRepairService`), avec ses gardes
 * (qualification, disponibilité) et son historisation.
 */
class RemplacementCandidature extends Model
{
    protected $table = 'remplacement_candidatures';

    public const STATUTS = ['soumise', 'acceptee', 'ecartee', 'retiree'];

    protected $fillable = [
        'teacher_id',
        'matiere_id',
        'message',
        'statut',
        'decision_motif',
        'decided_by',
        'decided_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    /**
     * @return BelongsTo<Matiere, $this>
     */
    public function matiere(): BelongsTo
    {
        return $this->belongsTo(Matiere::class);
    }

    public function estSoumise(): bool
    {
        return $this->statut === 'soumise';
    }
}
