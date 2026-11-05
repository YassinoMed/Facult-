<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LOOP 44 — Version d'une règle académique.
 *
 * Une règle n'est jamais modifiée en place après approbation : toute
 * évolution crée une nouvelle version. Le contenu publié est protégé par
 * une empreinte SHA-256 et par des gardes de modèle.
 */
class RegleAcademiqueVersion extends Model
{
    protected $table = 'regles_academiques_versions';

    public const STATUT_BROUILLON = 'BROUILLON';

    public const STATUT_PROPOSEE = 'PROPOSEE';

    public const STATUT_EN_REVUE = 'EN_REVUE';

    public const STATUT_EN_VALIDATION = 'EN_VALIDATION';

    public const STATUT_APPROUVEE = 'APPROUVEE';

    public const STATUT_CONFIRMEE = 'CONFIRMEE';

    public const STATUT_PUBLIEE = 'PUBLIEE';

    public const STATUT_SUSPENDUE = 'SUSPENDUE';

    public const STATUT_REMPLACEE = 'REMPLACEE';

    public const STATUT_EXPIREE = 'EXPIREE';

    public const STATUT_RETIREE = 'RETIREE';

    public const STATUTS = [
        self::STATUT_BROUILLON,
        self::STATUT_PROPOSEE,
        self::STATUT_EN_REVUE,
        self::STATUT_EN_VALIDATION,
        self::STATUT_APPROUVEE,
        self::STATUT_CONFIRMEE,
        self::STATUT_PUBLIEE,
        self::STATUT_SUSPENDUE,
        self::STATUT_REMPLACEE,
        self::STATUT_EXPIREE,
        self::STATUT_RETIREE,
    ];

    /** Catégories canoniques de sources de règles (PHASE 2 - LOOP 44). */
    public const CATEGORIE_TEXTE_NATIONAL = 'TEXTE_NATIONAL';

    public const CATEGORIE_REGLEMENT_ETABLISSEMENT = 'REGLEMENT_ETABLISSEMENT';

    public const CATEGORIE_MAQUETTE_PEDAGOGIQUE = 'MAQUETTE_PEDAGOGIQUE';

    public const CATEGORIE_DECISION_JURY = 'DECISION_JURY';

    public const CATEGORIE_DEROGATION_INDIVIDUELLE = 'DEROGATION_INDIVIDUELLE';

    public const CATEGORIE_PARAMETRE_TECHNIQUE = 'PARAMETRE_TECHNIQUE';

    public const CATEGORIES = [
        self::CATEGORIE_TEXTE_NATIONAL,
        self::CATEGORIE_REGLEMENT_ETABLISSEMENT,
        self::CATEGORIE_MAQUETTE_PEDAGOGIQUE,
        self::CATEGORIE_DECISION_JURY,
        self::CATEGORIE_DEROGATION_INDIVIDUELLE,
        self::CATEGORIE_PARAMETRE_TECHNIQUE,
    ];

    /** Champs figés dès qu'une version est approuvée. */
    public const CHAMPS_FIGES = [
        'code',
        'version',
        'contenu',
        'checksum',
        'reference_officielle',
        'date_effet',
        'date_fin',
    ];

    protected $fillable = [
        'code',
        'version',
        'statut',
        'contenu',
        'reference_officielle',
        'date_effet',
        'date_fin',
        'checksum',
        'cree_par_id',
        'approuve_par_id',
        'publie_par_id',
        'retire_par_id',
        'approuve_le',
        'publie_le',
        'retire_le',
        'motif_retrait',
    ];

    protected function casts(): array
    {
        return [
            'contenu' => 'array',
            'version' => 'integer',
            'date_effet' => 'date',
            'date_fin' => 'date',
            'approuve_le' => 'datetime',
            'publie_le' => 'datetime',
            'retire_le' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $regle): void {
            $statutOrigine = $regle->getOriginal('statut');

            if (! in_array($statutOrigine, [
                self::STATUT_APPROUVEE,
                self::STATUT_CONFIRMEE,
                self::STATUT_PUBLIEE,
                self::STATUT_SUSPENDUE,
                self::STATUT_REMPLACEE,
                self::STATUT_EXPIREE,
                self::STATUT_RETIREE,
            ], true)) {
                return;
            }

            foreach (self::CHAMPS_FIGES as $champ) {
                if ($regle->isDirty($champ)) {
                    throw new DomainException(
                        "Le champ « {$champ} » d'une règle {$statutOrigine} est immuable : créez une nouvelle version."
                    );
                }
            }
        });

        static::deleting(function (self $regle): void {
            if ($regle->statut !== self::STATUT_BROUILLON) {
                throw new DomainException(
                    "Une règle au statut {$regle->statut} ne peut pas être supprimée : retirez-la ou créez une nouvelle version."
                );
            }
        });
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par_id');
    }

    public function approbateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approuve_par_id');
    }

    public function publicateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'publie_par_id');
    }

    public function retirateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retire_par_id');
    }

    public function scopePubliee(Builder $query): Builder
    {
        return $query->where('statut', self::STATUT_PUBLIEE);
    }

    public function scopePourCode(Builder $query, string $code): Builder
    {
        return $query->where('code', $code);
    }

    public function estModifiable(): bool
    {
        return in_array($this->statut, [self::STATUT_BROUILLON, self::STATUT_EN_REVUE], true);
    }

    public function estPubliee(): bool
    {
        return $this->statut === self::STATUT_PUBLIEE;
    }

    public function estActiveA(CarbonInterface|string|null $date = null): bool
    {
        if (! $this->estPubliee() || $this->date_effet === null) {
            return false;
        }

        $reference = $date === null
            ? now()->startOfDay()
            : ($date instanceof CarbonInterface ? $date->copy()->startOfDay() : Carbon::parse($date)->startOfDay());

        if ($this->date_effet->greaterThan($reference)) {
            return false;
        }

        return $this->date_fin === null || $this->date_fin->greaterThanOrEqualTo($reference);
    }

    public function referenceOfficielleComplete(): bool
    {
        return $this->reference_officielle !== null && trim($this->reference_officielle) !== '';
    }

    public function getCategorie(): string
    {
        return $this->contenu['categorie'] ?? self::CATEGORIE_PARAMETRE_TECHNIQUE;
    }

    public function getTypeRegle(): string
    {
        return $this->contenu['type_regle'] ?? 'REGLE_GENERALE';
    }

    public function getDiplome(): ?string
    {
        return $this->contenu['diplome'] ?? null;
    }

    public function getEtablissement(): ?string
    {
        return $this->contenu['etablissement'] ?? null;
    }

    public function getFaculteId(): ?int
    {
        return isset($this->contenu['faculte_id']) ? (int) $this->contenu['faculte_id'] : null;
    }

    public function getFormation(): ?string
    {
        return $this->contenu['formation'] ?? null;
    }

    public function getSpecialite(): ?string
    {
        return $this->contenu['specialite'] ?? null;
    }

    public function getCohorte(): ?string
    {
        return $this->contenu['cohorte'] ?? null;
    }

    public function getSourceJuridique(): ?string
    {
        return $this->contenu['source_juridique'] ?? $this->reference_officielle;
    }

    public function getArticle(): ?string
    {
        return $this->contenu['article'] ?? null;
    }

    public function getAutoriteCompetente(): ?string
    {
        return $this->contenu['autorite_competente'] ?? null;
    }

    public function getReferenceValidation(): ?string
    {
        return $this->contenu['reference_validation'] ?? null;
    }
}
