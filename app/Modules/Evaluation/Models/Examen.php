<?php

namespace App\Modules\Evaluation\Models;

use App\Models\AnneeUniversitaire;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Schedule\Models\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Examen extends Model
{
    protected $table = 'evaluation_examens';

    public const TYPES = ['examen', 'controle', 'partiel', 'devoir', 'oral', 'projet', 'soutenance', 'rattrapage'];

    public const STATUTS = ['draft', 'planned', 'scheduled', 'published', 'ongoing', 'completed', 'locked', 'cancelled', 'archived'];

    /** Transitions serveur autorisées (le frontend ne décide jamais). */
    public const TRANSITIONS = [
        'draft' => ['planned', 'cancelled'],
        'planned' => ['scheduled', 'cancelled'],
        'scheduled' => ['published', 'cancelled'],
        'published' => ['ongoing', 'cancelled'],
        'ongoing' => ['completed', 'cancelled'],
        'completed' => ['locked', 'archived'],
        'locked' => ['archived'],
        'cancelled' => ['archived'],
        'archived' => [],
    ];

    protected $fillable = [
        'code', 'session_id', 'annee_universitaire_id', 'semestre_lmd_id', 'programme_id',
        'niveau_id', 'groupe_id', 'libelle', 'type', 'statut',
        'description', 'observations', 'responsable_id', 'published_at',
    ];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(SessionExamen::class, 'session_id');
    }

    public function anneeUniversitaire(): BelongsTo
    {
        return $this->belongsTo(AnneeUniversitaire::class);
    }

    public function semestreLmd(): BelongsTo
    {
        return $this->belongsTo(SemestreLmd::class, 'semestre_lmd_id');
    }

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    public function niveau(): BelongsTo
    {
        return $this->belongsTo(Niveau::class, 'niveau_id');
    }

    public function groupe(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'groupe_id');
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    public function epreuves(): HasMany
    {
        return $this->hasMany(Epreuve::class, 'examen_id');
    }

    /** Versions successives soumises au circuit de gouvernance (§2.7). */
    public function validations(): HasMany
    {
        return $this->hasMany(PlanningValidation::class, 'examen_id');
    }

    public function peutTransiterVers(string $cible): bool
    {
        return in_array($cible, self::TRANSITIONS[$this->statut] ?? [], true);
    }

    public function estCritique(): bool
    {
        return in_array($this->statut, ['published', 'ongoing', 'completed', 'locked', 'archived'], true);
    }

    /**
     * Faculté propriétaire de l'examen (programme prioritaire, sinon
     * groupe) ; null = transversal. Source unique pour le périmètre
     * (policy + contrôleurs) — audit sécurité 04/10/2026.
     */
    public function faculteId(): ?int
    {
        $this->loadMissing(['programme', 'groupe']);

        $faculte = $this->programme?->faculte_id ?? $this->groupe?->faculte_id;

        return $faculte === null ? null : (int) $faculte;
    }

    /**
     * Filtre de listing : visible dans la faculté donnée (ressources
     * transversales — sans programme ni groupe — comprises).
     */
    public function scopePourFaculteId(Builder $query, int $faculteId): void
    {
        $query->where(fn ($w) => $w
            ->whereHas('programme', fn ($p) => $p->where('faculte_id', $faculteId))
            ->orWhereHas('groupe', fn ($g) => $g->where('faculte_id', $faculteId))
            ->orWhere(fn ($t) => $t->whereNull('programme_id')->whereNull('groupe_id')));
    }
}
