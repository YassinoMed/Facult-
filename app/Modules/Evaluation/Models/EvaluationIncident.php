<?php

namespace App\Modules\Evaluation\Models;

use App\Models\Etudiant;
use App\Models\User;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Incident d'épreuve (§14) : constat post-hoc (fraude, matériel,
 * retard…), lié à l'épreuve et optionnellement à un candidat.
 * Le verrou de l'épreuve ne bloque jamais le signalement.
 */
class EvaluationIncident extends Model
{
    protected $table = 'evaluation_incidents';

    public const TYPES = ['retard', 'fraude', 'materiel', 'copie_manquante', 'probleme_salle', 'autre'];

    public const STATUTS = ['signale', 'en_cours', 'resolu'];

    /** Transitions serveur : le frontend ne décide jamais. */
    public const TRANSITIONS = [
        'signale' => ['en_cours', 'resolu'],
        'en_cours' => ['resolu'],
        'resolu' => [],
    ];

    protected $fillable = [
        'epreuve_id',
        'type',
        'description',
        'etudiant_id',
        'created_by',
        'teacher_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (EvaluationIncident $incident): void {
            if (empty($incident->uuid)) {
                $incident->uuid = (string) Str::uuid();
            }

            if (empty($incident->statut)) {
                $incident->statut = 'signale';
            }
        });
    }

    public function epreuve(): BelongsTo
    {
        return $this->belongsTo(Epreuve::class, 'epreuve_id');
    }

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class);
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function enseignantSignaleur(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    public function estClos(): bool
    {
        return $this->statut === 'resolu';
    }

    public function peutTransiterVers(string $cible): bool
    {
        return in_array($cible, self::TRANSITIONS[$this->statut] ?? [], true);
    }
}
