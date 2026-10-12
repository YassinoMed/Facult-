<?php

namespace App\Models;

use App\Modules\Enseignement\Models\ComposanteEvaluation;
use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Matiere extends Model
{
    /**
     * ECUE = Matière (choix architectural LOT 2 : jamais de FK `ecue_id`
     * dupliquée — notes, évaluations et délibérations référencent
     * `matieres.code`). Champs legacy `niveau`/`filiere`/`semestre`
     * (tinyint) : YELLOW — encore lus (comparaisons, exports), source
     * LMD = chaîne UE→semestre→niveau ; suppression interdite sans
     * migration + audit d'usages.
     */
    protected $fillable = [
        'code',
        'nom',
        'coefficient',
        'credits',
        'niveau',
        'filiere',
        'semestre',
        // Référentiel LMD : la matière EST l'ECUE de son UE (§14).
        'ue_id',
        'responsable_teacher_id',
        'description',
        'volume_cm',
        'volume_td',
        'volume_tp',
        'type_evaluation',
        'ordre',
        'actif',
    ];

    protected $casts = [
        'coefficient' => 'decimal:2',
        'actif' => 'boolean',
    ];

    /**
     * INV-4 (audit 08/10/2026) : le code matière est une donnée métier
     * canonique — normalisée (majuscules + trim) à TOUTE écriture, toutes
     * voies (CRUD, imports, seeders). L'unicité globale est réelle, pas
     * seulement apparente (SQLite est sensible à la casse, la collation
     * MySQL dépend du serveur).
     */
    protected static function booted(): void
    {
        static::saving(function (self $matiere) {
            $matiere->code = mb_strtoupper(trim((string) $matiere->code));
        });
    }

    /**
     * Normalisation canonique du code matière (source unique, réutilisée
     * par les définitions d'import et les consommateurs).
     */
    public static function normaliserCode(?string $code): string
    {
        return mb_strtoupper(trim((string) $code));
    }

    /**
     * Résolution canonique par code normalisé — remplace les recherches
     * hétérogènes (`where('code', …)` brut selon les définitions).
     */
    public static function resoudreParCode(?string $code): ?self
    {
        $normalise = self::normaliserCode($code);

        if ($normalise === '') {
            return null;
        }

        return self::where('code', $normalise)->first();
    }

    /**
     * Périmètre faculté (INV-6, audit 08/10/2026) : une matière est
     * transversale (ue_id null = GLOBAL_UNIVERSITE, décision D-A) ou
     * attachée à une UE du programme de la faculté. Source unique du
     * scope matières (recherche, exports, DataExchange).
     */
    public function scopePourFaculte(Builder $query, ?int $faculteId): Builder
    {
        if ($faculteId === null) {
            return $query;
        }

        return $query->where(function ($w) use ($faculteId) {
            $w->whereNull('ue_id')
                ->orWhereHas('ue.programme', fn ($p) => $p->where('faculte_id', $faculteId));
        });
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    public function ue(): BelongsTo
    {
        return $this->belongsTo(UniteEnseignement::class, 'ue_id');
    }

    /**
     * Phase 3 : enseignements dispensant cette ECUE + évaluation.
     */
    public function enseignements(): HasMany
    {
        return $this->hasMany(Enseignement::class);
    }

    public function composantes(): HasMany
    {
        return $this->hasMany(ComposanteEvaluation::class);
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'responsable_teacher_id');
    }

    /**
     * Prérequis exigés avant cette ECUE (§17).
     *
     * @return BelongsToMany<Matiere, $this>
     */
    public function prerequis(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class, 'ecue_prerequis', 'matiere_id', 'prerequis_id'
        )->withTimestamps();
    }

    /**
     * ECUE qui exigent celle-ci.
     *
     * @return BelongsToMany<Matiere, $this>
     */
    public function requisePar(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class, 'ecue_prerequis', 'prerequis_id', 'matiere_id'
        )->withTimestamps();
    }

    /**
     * Volume horaire total CM + TD + TP (§15, règle centralisée).
     */
    public function volumeTotal(): int
    {
        return (int) $this->volume_cm + (int) $this->volume_td + (int) $this->volume_tp;
    }

    /**
     * Niveau AFFICHÉ (GO condition 3, audit 08/10/2026) : dérivé de la
     * chaîne LMD (source de vérité) quand l'ECUE est rattachée à une
     * UE ; repli sur le champ legacy pour les matières standalone.
     * Corrige les divergences prouvées (legacy ≠ chaîne).
     */
    public function niveauAffiche(): string
    {
        if ($this->ue_id !== null) {
            $this->loadMissing('ue.semestre.niveau');

            if ($this->ue?->semestre?->niveau !== null) {
                return (string) $this->ue->semestre->niveau->code;
            }
        }

        return (string) $this->niveau;
    }

    /**
     * Semestre RELATIF dans le niveau (1|2) : dérivé du semestre LMD
     * absolu (`numero` 1..6) quand rattachée ; repli legacy sinon.
     * Source unique pour l'attribution note → semestre d'inscription.
     */
    public function semestreRelatif(): int
    {
        if ($this->ue_id !== null) {
            $this->loadMissing('ue.semestre');

            $numero = $this->ue?->semestre?->numero;

            if ($numero !== null) {
                return ((int) $numero - 1) % 2 + 1;
            }
        }

        return (int) $this->semestre;
    }

    /**
     * Libellé complet : « MATH101 — Analyse I ».
     */
    public function getLibelleCompletAttribute(): string
    {
        return $this->code.' — '.$this->nom;
    }

    /**
     * Faculté propriétaire via la chaîne référentiel (UE → programme) ;
     * null = matière transversale. Source unique pour le périmètre
     * (audit sécurité 04/10/2026).
     */
    public function faculteId(): ?int
    {
        $this->loadMissing(['ue.programme']);

        $faculte = $this->ue?->programme?->faculte_id;

        return $faculte === null ? null : (int) $faculte;
    }
}
