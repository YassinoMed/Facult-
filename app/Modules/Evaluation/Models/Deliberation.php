<?php

namespace App\Modules\Evaluation\Models;

use App\Models\AnneeUniversitaire;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Schedule\Models\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Deliberation extends Model
{
    protected $table = 'evaluation_deliberations';

    public const STATUTS = ['draft', 'prepared', 'in_review', 'convened', 'in_progress', 'validated', 'published', 'locked', 'archived'];

    /** Transitions serveur (Phase 10) : le frontend ne décide jamais. */
    public const TRANSITIONS = [
        'draft' => ['prepared'],
        'prepared' => ['prepared', 'in_review', 'validated'],
        'in_review' => ['convened', 'validated'],
        'convened' => ['in_progress', 'validated'],
        'in_progress' => ['validated'],
        'validated' => ['published'],
        'published' => ['locked'],
        'locked' => ['archived'],
        'archived' => [],
    ];

    protected $fillable = [
        'code', 'annee_universitaire_id', 'session_id', 'semestre_lmd_id', 'programme_id',
        'niveau_id', 'groupe_id', 'statut', 'regles_appliquees', 'anomalies', 'created_by',
        'published_at', 'verrouillee_at', 'resultats_invalides_le',
    ];

    protected function casts(): array
    {
        return [
            'regles_appliquees' => 'array',
            'anomalies' => 'array',
            'published_at' => 'datetime',
            'verrouillee_at' => 'datetime',
            'resultats_invalides_le' => 'datetime',
        ];
    }

    public function anneeUniversitaire(): BelongsTo
    {
        return $this->belongsTo(AnneeUniversitaire::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(SessionExamen::class, 'session_id');
    }

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    public function semestreLmd(): BelongsTo
    {
        return $this->belongsTo(SemestreLmd::class, 'semestre_lmd_id');
    }

    public function niveau(): BelongsTo
    {
        return $this->belongsTo(Niveau::class, 'niveau_id');
    }

    public function groupe(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'groupe_id');
    }

    public function membres(): HasMany
    {
        return $this->hasMany(JuryMembre::class, 'deliberation_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(DecisionDeliberation::class, 'deliberation_id');
    }

    public function estVerrouillee(): bool
    {
        return $this->verrouillee_at !== null || in_array($this->statut, ['locked', 'archived'], true);
    }

    public function peutTransiterVers(string $cible): bool
    {
        return in_array($cible, self::TRANSITIONS[$this->statut] ?? [], true);
    }

    public function anomaliesBloquantes(): array
    {
        return array_values(array_filter(
            $this->anomalies ?? [],
            fn (array $a) => ($a['bloquant'] ?? false) === true
        ));
    }

    /**
     * Faculté propriétaire (programme prioritaire, sinon groupe) ;
     * null = transversal. Source unique pour le périmètre
     * (audit sécurité 04/10/2026).
     */
    public function faculteId(): ?int
    {
        $this->loadMissing(['programme', 'groupe']);

        $faculte = $this->programme?->faculte_id ?? $this->groupe?->faculte_id;

        return $faculte === null ? null : (int) $faculte;
    }

    /**
     * Filtre de listing : visible dans la faculté donnée (ressources
     * transversales comprises).
     */
    public function scopePourFaculteId(Builder $query, int $faculteId): void
    {
        $query->where(fn ($w) => $w
            ->whereHas('programme', fn ($p) => $p->where('faculte_id', $faculteId))
            ->orWhereHas('groupe', fn ($g) => $g->where('faculte_id', $faculteId))
            ->orWhere(fn ($t) => $t->whereNull('programme_id')->whereNull('groupe_id')));
    }
}
