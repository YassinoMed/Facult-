<?php

namespace App\Modules\Evaluation\Models;

use App\Models\AnneeUniversitaire;
use App\Modules\Referentiel\Models\Programme;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SessionExamen extends Model
{
    protected $table = 'evaluation_sessions';

    public const TYPES = ['principale', 'rattrapage', 'exceptionnelle'];

    public const STATUTS = ['draft', 'open', 'ongoing', 'closed', 'locked', 'archived'];

    /** Transitions serveur (Phase 10). */
    public const TRANSITIONS = [
        'draft' => ['open'],
        'open' => ['ongoing', 'closed'],
        'ongoing' => ['closed'],
        'closed' => ['locked', 'archived'],
        'locked' => ['archived'],
        'archived' => [],
    ];

    protected $fillable = [
        'annee_universitaire_id', 'session_origine_id', 'code', 'nom', 'type',
        'date_debut', 'date_fin', 'statut', 'description', 'regles',
        'created_by', 'verrouillee_at',
    ];

    protected function casts(): array
    {
        return [
            'date_debut' => 'date',
            'date_fin' => 'date',
            'regles' => 'array',
            'verrouillee_at' => 'datetime',
        ];
    }

    public function anneeUniversitaire(): BelongsTo
    {
        return $this->belongsTo(AnneeUniversitaire::class);
    }

    /**
     * Phase 5 §43 : session principale d'origine d'un rattrapage
     * (même année universitaire, jamais de chaîne devinée).
     */
    public function origine(): BelongsTo
    {
        return $this->belongsTo(self::class, 'session_origine_id');
    }

    public function rattrapages(): HasMany
    {
        return $this->hasMany(self::class, 'session_origine_id');
    }

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    public function examens(): HasMany
    {
        return $this->hasMany(Examen::class, 'session_id');
    }

    public function epreuves(): HasMany
    {
        return $this->hasMany(Epreuve::class, 'session_id');
    }

    public function estVerrouillee(): bool
    {
        return $this->verrouillee_at !== null || in_array($this->statut, ['locked', 'archived'], true);
    }

    public function peutTransiterVers(string $cible): bool
    {
        return in_array($cible, self::TRANSITIONS[$this->statut] ?? [], true);
    }

    public function contientDate(string $date): bool
    {
        return $date >= (string) $this->date_debut && $date <= (string) $this->date_fin;
    }

    /**
     * Mode d'organisation de la session (Mode A: pédagogique, Mode B: éligibilité).
     */
    public function modeOrganisation(): string
    {
        return $this->regles['mode_organisation'] ?? 'mode_a';
    }

    public function modeOrganisationLabel(): string
    {
        return $this->modeOrganisation() === 'mode_b'
            ? 'Mode B (Groupes par Éligibilité)'
            : 'Mode A (Groupes Pédagogiques)';
    }

    public function estRattrapage(): bool
    {
        return $this->type === 'rattrapage';
    }

    public function estSessionPrincipale(): bool
    {
        return $this->type === 'principale';
    }

    /**
     * Filtre de listing : visible dans la faculté donnée (via examens ou session transversale).
     */
    public function scopePourFaculteId(Builder $query, int $faculteId): void
    {
        $query->where(fn ($w) => $w
            ->whereHas('examens', fn ($e) => $e->pourFaculteId($faculteId))
            ->orWhereDoesntHave('examens')
        );
    }
}
