<?php

namespace App\Modules\Evaluation\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Suivi d'un traitement d'affectation automatique des surveillances
 * exécuté en file (Job). Compteurs et résumé JSON uniquement —
 * sans PII (identifiants et statistiques sans noms).
 *
 * Statuts repris du cahier des charges (§5) : PENDING → RUNNING →
 * COMPLETED | COMPLETED_WITH_WARNINGS | FAILED, plus CANCELLED
 * (annulation coopérative entre deux épreuves).
 */
class SurveillanceAssignmentRun extends Model
{
    protected $table = 'surveillance_assignment_runs';

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_RUNNING = 'RUNNING';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_COMPLETED_WITH_WARNINGS = 'COMPLETED_WITH_WARNINGS';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const STATUTS_TERMINAUX = [
        self::STATUS_COMPLETED,
        self::STATUS_COMPLETED_WITH_WARNINGS,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'uuid',
        'session_examen_id',
        'faculte_id',
        'user_id',
        'status',
        'mode',
        'progress',
        'total_epreuves',
        'epreuves_traitees',
        'affectations_creees',
        'affectations_modifiees',
        'conflits',
        'erreurs',
        'started_at',
        'finished_at',
        'error_message',
        'result_summary',
        'config_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'result_summary' => 'array',
            'config_snapshot' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(SessionExamen::class, 'session_examen_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isTerminated(): bool
    {
        return in_array($this->status, self::STATUTS_TERMINAUX, true);
    }

    public function elapsedSeconds(): ?int
    {
        if ($this->started_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at ?? now());
    }

    public function pourcentage(): int
    {
        if ($this->total_epreuves <= 0) {
            return (int) $this->progress;
        }

        return (int) min(100, round($this->epreuves_traitees / $this->total_epreuves * 100));
    }

    /**
     * Run actif (PENDING/RUNNING) pour un couple session × faculté,
     * ou null. Source unique de la garde d'idempotence.
     */
    public static function actifPour(int $sessionId, ?int $faculteId): ?self
    {
        return static::where('session_examen_id', $sessionId)
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_RUNNING])
            ->latest('id')
            ->first();
    }

    /**
     * État exposé au polling et à la page de suivi (sans PII).
     *
     * @return array<string, mixed>
     */
    public function progression(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'session_examen_id' => $this->session_examen_id,
            'faculte_id' => $this->faculte_id,
            'status' => $this->status,
            'mode' => $this->mode,
            'progress' => $this->progress,
            'total_epreuves' => $this->total_epreuves,
            'epreuves_traitees' => $this->epreuves_traitees,
            'affectations_creees' => $this->affectations_creees,
            'affectations_modifiees' => $this->affectations_modifiees,
            'conflits' => $this->conflits,
            'erreurs' => $this->erreurs,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'elapsed_seconds' => $this->elapsedSeconds(),
            'error_message' => $this->error_message,
            'result_summary' => $this->result_summary,
            'is_terminated' => $this->isTerminated(),
        ];
    }
}
