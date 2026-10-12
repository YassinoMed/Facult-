<?php

namespace App\Modules\Evaluation\Models;

use App\Models\AnneeUniversitaire;
use App\Models\Matiere;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Epreuve extends Model
{
    protected $table = 'evaluation_epreuves';

    public const STATUTS = ['draft', 'scheduled', 'published', 'ongoing', 'completed', 'locked', 'cancelled'];

    /** Transitions serveur (Phase 10). */
    public const TRANSITIONS = [
        'draft' => ['scheduled', 'cancelled'],
        'scheduled' => ['published', 'cancelled'],
        'published' => ['ongoing', 'completed', 'cancelled'],
        'ongoing' => ['completed', 'cancelled'],
        'completed' => ['locked'],
        'locked' => [],
        'cancelled' => [],
    ];

    protected $fillable = [
        'code', 'examen_id', 'session_id', 'annee_universitaire_id', 'matiere_id', 'ue_id',
        'groupe_id', 'sous_groupe_id', 'salle_id', 'date', 'heure_debut', 'heure_fin',
        'duree_minutes', 'type_evaluation', 'coefficient', 'bareme', 'note_max',
        'capacite_necessaire', 'statut',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'coefficient' => 'decimal:2',
            'bareme' => 'decimal:2',
            'note_max' => 'decimal:2',
        ];
    }

    public function examen(): BelongsTo
    {
        return $this->belongsTo(Examen::class, 'examen_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(SessionExamen::class, 'session_id');
    }

    public function anneeUniversitaire(): BelongsTo
    {
        return $this->belongsTo(AnneeUniversitaire::class);
    }

    /** ECUE : Matiere existante, jamais dupliquée. */
    public function matiere(): BelongsTo
    {
        return $this->belongsTo(Matiere::class);
    }

    /** UE : UniteEnseignement existante. */
    public function ue(): BelongsTo
    {
        return $this->belongsTo(UniteEnseignement::class, 'ue_id');
    }

    public function groupe(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'groupe_id');
    }

    public function salle(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'salle_id');
    }

    public function surveillants(): HasMany
    {
        return $this->hasMany(Surveillance::class, 'epreuve_id');
    }

    public function candidats(): HasMany
    {
        return $this->hasMany(Candidat::class, 'epreuve_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(NoteEpreuve::class, 'epreuve_id');
    }

    public function compostages(): HasMany
    {
        return $this->hasMany(EvaluationCompostage::class, 'epreuve_id');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(EvaluationIncident::class, 'epreuve_id');
    }

    public function estVerrouillee(): bool
    {
        return in_array($this->statut, ['locked', 'cancelled'], true);
    }

    public function peutTransiterVers(string $cible): bool
    {
        return in_array($cible, self::TRANSITIONS[$this->statut] ?? [], true);
    }

    public function chevauche(Epreuve $autre): bool
    {
        if ($this->date->format('Y-m-d') !== $autre->date->format('Y-m-d')) {
            return false;
        }

        return $this->heure_debut < $autre->heure_fin && $autre->heure_debut < $this->heure_fin;
    }

    /**
     * Faculté propriétaire de l'épreuve (groupe prioritaire, sinon la
     * chaîne ECUE → UE → programme) ; null = transversal. Source unique
     * pour le périmètre (audit sécurité 04/10/2026).
     */
    public function faculteId(): ?int
    {
        $this->loadMissing(['groupe', 'matiere.ue.programme']);

        $faculte = $this->groupe?->faculte_id ?? $this->matiere?->ue?->programme?->faculte_id;

        return $faculte === null ? null : (int) $faculte;
    }

    /**
     * Filtre de listing : visible dans la faculté donnée (ressources
     * transversales comprises).
     */
    public function scopePourFaculteId(Builder $query, int $faculteId): void
    {
        $query->where(fn ($w) => $w
            ->whereHas('groupe', fn ($g) => $g->where('faculte_id', $faculteId))
            ->orWhere(fn ($t) => $t->whereNull('groupe_id')
                ->whereHas('matiere.ue.programme', fn ($p) => $p->where('faculte_id', $faculteId)))
            ->orWhere(fn ($t) => $t->whereNull('groupe_id')->whereDoesntHave('matiere.ue.programme')));
    }
}
