<?php

namespace App\Models;

use App\Modules\Schedule\Models\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Modèle dédié de proposition d'affectation (LOOP 10) :
 * Isole juridiquement et fonctionnellement la proposition calculée de l'affectation réelle.
 *
 * @property int $id
 * @property int $student_id
 * @property int|null $inscription_id
 * @property int $annee_universitaire_id
 * @property int $proposed_group_id
 * @property string $mode
 * @property int $score
 * @property string $certitude
 * @property string $statut
 * @property array|null $reasons
 * @property array|null $metadata
 * @property int|null $valide_par
 * @property Carbon|null $valide_le
 * @property string|null $motif_refus
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AffectationProposal extends Model
{
    protected $table = 'affectation_proposals';

    public const STATUT_EN_ATTENTE = 'en_attente';

    public const STATUT_ACCEPTEE = 'acceptee';

    public const STATUT_REJETEE = 'rejetee';

    public const STATUT_MODIFIEE = 'modifiee';

    public const STATUT_EXPIREE = 'expiree';

    // Alias anglophones (Phase 10)
    public const STATUS_PENDING = self::STATUT_EN_ATTENTE;

    public const STATUS_ACCEPTED = self::STATUT_ACCEPTEE;

    public const STATUS_REJECTED = self::STATUT_REJETEE;

    public const STATUS_MODIFIED = self::STATUT_MODIFIEE;

    public const STATUS_EXPIRED = self::STATUT_EXPIREE;

    public const CERTITUDE_ELEVEE = 'elevee';

    public const CERTITUDE_MOYENNE = 'moyenne';

    public const CERTITUDE_FAIBLE = 'faible';

    public const MODE_AUTOMATIQUE = 'automatique';

    public const MODE_SEMI_AUTOMATIQUE = 'semi_automatique';

    protected $fillable = [
        'student_id',
        'inscription_id',
        'annee_universitaire_id',
        'proposed_group_id',
        'mode',
        'score',
        'certitude',
        'statut',
        'reasons',
        'metadata',
        'valide_par',
        'valide_le',
        'motif_refus',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'reasons' => 'array',
            'metadata' => 'array',
            'valide_le' => 'datetime',
        ];
    }

    public function getStatusAttribute(): ?string
    {
        return $this->attributes['statut'] ?? null;
    }

    public function setStatusAttribute(?string $valeur): void
    {
        $this->attributes['statut'] = $valeur;
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class, 'student_id');
    }

    public function inscription(): BelongsTo
    {
        return $this->belongsTo(InscriptionAcademique::class, 'inscription_id');
    }

    public function anneeUniversitaire(): BelongsTo
    {
        return $this->belongsTo(AnneeUniversitaire::class, 'annee_universitaire_id');
    }

    public function proposedGroup(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'proposed_group_id');
    }

    public function validePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valide_par');
    }

    public function scopeEnAttente(Builder $query): Builder
    {
        return $query->where('statut', self::STATUT_EN_ATTENTE);
    }

    public function scopePourAnnee(Builder $query, int $anneeId): Builder
    {
        return $query->where('annee_universitaire_id', $anneeId);
    }

    public function estEnAttente(): bool
    {
        return $this->statut === self::STATUT_EN_ATTENTE;
    }
}
