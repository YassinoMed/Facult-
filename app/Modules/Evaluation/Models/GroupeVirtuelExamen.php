<?php

namespace App\Modules\Evaluation\Models;

use App\Models\Matiere;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Groupe virtuel d'examen (§2.1).
 *
 * Regroupement CALCULÉ pour un module et une épreuve donnés : il agrège
 * plusieurs groupes sources sans jamais modifier les inscriptions ni les
 * groupes académiques d'origine. Chaque membre porte une décision
 * d'éligibilité explicable (`eligible`, `non_eligible`, `anomalie`).
 *
 * Une proposition ne vaut ni certification ni publication.
 */
class GroupeVirtuelExamen extends Model
{
    protected $table = 'evaluation_groupes_virtuels';

    public const STATUT_PROPOSE = 'propose';

    public const STATUT_REMPLACE = 'remplace';

    public const STATUT_ANNULE = 'annule';

    public const STATUTS = [self::STATUT_PROPOSE, self::STATUT_REMPLACE, self::STATUT_ANNULE];

    protected $fillable = [
        'uuid', 'examen_id', 'epreuve_id', 'matiere_id', 'programme_id', 'niveau_id',
        'filiere', 'libelle', 'cle', 'statut', 'sources', 'cree_par', 'remplace_par_id',
    ];

    protected function casts(): array
    {
        return ['sources' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(function (GroupeVirtuelExamen $groupe): void {
            if (empty($groupe->uuid)) {
                $groupe->uuid = (string) Str::uuid();
            }
        });
    }

    public function examen(): BelongsTo
    {
        return $this->belongsTo(Examen::class, 'examen_id');
    }

    public function epreuve(): BelongsTo
    {
        return $this->belongsTo(Epreuve::class, 'epreuve_id');
    }

    public function matiere(): BelongsTo
    {
        return $this->belongsTo(Matiere::class, 'matiere_id');
    }

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    public function niveau(): BelongsTo
    {
        return $this->belongsTo(Niveau::class);
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }

    public function remplacePar(): BelongsTo
    {
        return $this->belongsTo(self::class, 'remplace_par_id');
    }

    public function membres(): HasMany
    {
        return $this->hasMany(GroupeVirtuelMembre::class, 'groupe_virtuel_id');
    }

    public function sourcesGroupes(): HasMany
    {
        return $this->hasMany(GroupeVirtuelMembre::class, 'groupe_virtuel_id')
            ->select('groupe_source_id')
            ->distinct();
    }

    public function estActif(): bool
    {
        return $this->statut === self::STATUT_PROPOSE;
    }

    public function effectifEligible(): int
    {
        return $this->membres()->where('decision', GroupeVirtuelMembre::DECISION_ELIGIBLE)->count();
    }

    /**
     * Identifiant stable du regroupement : examen + module + périmètre
     * académique + sources triées. Deux propositions identiques partagent
     * la même clé et ne doivent pas être dupliquées.
     *
     * @param  array<int, int>  $sourcesIds
     */
    public static function calculerCle(
        int $examenId,
        ?int $matiereId,
        ?int $programmeId,
        ?int $niveauId,
        ?string $filiere,
        array $sourcesIds,
    ): string {
        $sources = array_values(array_unique(array_map('intval', $sourcesIds)));
        sort($sources);

        return hash('sha256', (string) json_encode([
            'examen' => $examenId,
            'matiere' => $matiereId,
            'programme' => $programmeId,
            'niveau' => $niveauId,
            'filiere' => $filiere,
            'sources' => $sources,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Groupes sources distincts réellement représentés (pour l'affichage
     * et l'audit, sans requête supplémentaire côté vue).
     *
     * @return array<int, int>
     */
    public function identifiantsSources(): array
    {
        return collect($this->sources ?? [])->map(fn ($id) => (int) $id)->values()->all();
    }

    public function libelleGroupe(Group $groupe): string
    {
        return $groupe->code.' — '.$groupe->nom;
    }

    /**
     * Résout la faculté propriétaire du groupe virtuel sans jamais deviner.
     */
    public function faculteId(): ?int
    {
        $this->loadMissing([
            'programme',
            'examen.programme',
            'examen.groupe',
            'epreuve.groupe',
            'epreuve.matiere.ue.programme',
            'matiere.ue.programme',
        ]);

        $faculte = $this->programme?->faculte_id
            ?? $this->examen?->faculteId()
            ?? $this->epreuve?->faculteId()
            ?? $this->matiere?->ue?->programme?->faculte_id;

        if ($faculte !== null) {
            return (int) $faculte;
        }

        $sourcesIds = $this->identifiantsSources();
        if ($sourcesIds !== []) {
            $faculteSource = Group::query()->whereIn('id', $sourcesIds)->whereNotNull('faculte_id')->value('faculte_id');
            if ($faculteSource !== null) {
                return (int) $faculteSource;
            }
        }

        return null;
    }

    /**
     * Scope d'isolation facultaire côté serveur.
     */
    public function scopePourFaculteId(Builder $query, int $faculteId): void
    {
        $query->where(function (Builder $w) use ($faculteId) {
            $w->whereHas('programme', fn ($p) => $p->where('faculte_id', $faculteId))
                ->orWhere(function (Builder $sub) use ($faculteId) {
                    $sub->whereNull('programme_id')
                        ->whereHas('examen', fn ($e) => $e->pourFaculteId($faculteId));
                })
                ->orWhere(function (Builder $sub) use ($faculteId) {
                    $sub->whereNull('programme_id')
                        ->whereHas('epreuve', fn ($ep) => $ep->pourFaculteId($faculteId));
                })
                ->orWhere(function (Builder $sub) use ($faculteId) {
                    $sub->whereNull('programme_id')
                        ->whereHas('matiere.ue.programme', fn ($p) => $p->where('faculte_id', $faculteId));
                });
        });
    }
}
