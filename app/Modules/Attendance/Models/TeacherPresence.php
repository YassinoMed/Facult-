<?php

namespace App\Modules\Attendance\Models;

use App\Models\TeacherReclamation;
use App\Models\User;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Observation et rapprochement de la présence d'un enseignant pour une séance d'emploi du temps donnée.
 *
 * Sépare strictement :
 * - Le statut calculé automatiquement par l'algorithme déterministe (fail-soft, jamais d'absence validée arbitraire) ;
 * - Le statut officiellement validé ou corrigé par une autorité humaine habilitée (motif obligatoire).
 */
class TeacherPresence extends Model
{
    protected $table = 'teacher_presences';

    public const STATUTS_CALCULES = [
        'a_venir',
        'en_cours',
        'presence_confirmee',
        'retard_potentiel',
        'pointage_manquant',
        'absence_a_verifier',
        'seance_annulee',
        'dispense_mission',
    ];

    public const STATUTS_VALIDES = [
        'presence_confirmee',
        'absence_validee',
        'retard_justifie',
        'dispense_mission',
        'absence_rejetee',
    ];

    protected $fillable = [
        'schedule_slot_id',
        'teacher_id',
        'date',
        'heure_arrivee',
        'heure_depart',
        'statut_calcule',
        'statut_valide',
        'retard_minutes',
        'motif',
        'valide_par',
        'valide_le',
        'historique',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'valide_le' => 'datetime',
            'retard_minutes' => 'integer',
            'historique' => 'array',
        ];
    }

    /**
     * Statut effectif priorisant la décision humaine sur l'état calculé.
     */
    public function statutEffectif(): string
    {
        return $this->statut_valide ?? $this->statut_calcule;
    }

    public function getStatutEffectifAttribute(): string
    {
        return $this->statutEffectif();
    }

    public function getLibelleStatutEffectifAttribute(): string
    {
        return match ($this->statutEffectif()) {
            'a_venir' => 'À venir',
            'en_cours' => 'En cours',
            'presence_confirmee' => 'Présence confirmée',
            'retard_potentiel' => 'Retard potentiel',
            'pointage_manquant' => 'Pointage manquant',
            'absence_a_verifier' => 'Absence à vérifier',
            'absence_validee' => 'Absence validée',
            'retard_justifie' => 'Retard justifié',
            'seance_annulee' => 'Séance annulée',
            'dispense_mission' => 'Dispense / Mission',
            'absence_rejetee' => 'Absence rejetée',
            default => ucfirst(str_replace('_', ' ', $this->statutEffectif())),
        };
    }

    public function estValide(): bool
    {
        return $this->statut_valide !== null;
    }

    public function estAbsence(): bool
    {
        return in_array($this->statutEffectif(), ['absence_a_verifier', 'absence_validee'], true);
    }

    public function estEnRetard(): bool
    {
        return $this->statutEffectif() === 'retard_potentiel' || ($this->retard_minutes > 0 && $this->statutEffectif() !== 'retard_justifie');
    }

    /**
     * Ajoute une trace horodatée et non destructrice à l'historique d'audit JSON.
     *
     * @param  array<string, mixed>  $details
     */
    public function ajouterTrace(string $action, ?string $motif, ?int $userId, array $details = []): void
    {
        $historique = $this->historique ?? [];

        $historique[] = [
            'date' => now()->toIso8601String(),
            'action' => $action,
            'motif' => $motif,
            'user_id' => $userId,
            'ip' => request()?->ip(),
            'details' => $details,
        ];

        $this->historique = $historique;
    }

    /**
     * @return BelongsTo<ScheduleSlot, $this>
     */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(ScheduleSlot::class, 'schedule_slot_id');
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function validePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valide_par');
    }

    /**
     * @return HasMany<TeacherReclamation, $this>
     */
    public function reclamations(): HasMany
    {
        return $this->hasMany(TeacherReclamation::class, 'teacher_presence_id');
    }
}
