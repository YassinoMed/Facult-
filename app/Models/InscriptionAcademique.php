<?php

namespace App\Models;

use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Inscription académique d'un étudiant pour une année universitaire.
 * Unique par (etudiant, année universitaire).
 */
class InscriptionAcademique extends Model
{
    protected $table = 'inscriptions_academiques';

    protected $fillable = [
        'etudiant_id',
        'annee_universitaire_id',
        'programme_id', // Phase 2 (additif, nullable).
        'niveau_id', // Phase 2 (additif, nullable).
        'niveau',
        'filiere',
        'groupe',
        'statut',
        'type', // Phase 2 : premiere, reinscription, transfert…
        'regime', // Phase 2 : initiale, continue, alternance.
        'paye', // Paiement externe : simple indicateur oui/non.
        'moyenne_annuelle',
        'credits_obtenus',
        'credits_total',
        'decision',
        'observation',
    ];

    protected $casts = [
        'paye' => 'boolean',
        'moyenne_annuelle' => 'decimal:2',
    ];

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class);
    }

    public function anneeUniversitaire(): BelongsTo
    {
        return $this->belongsTo(AnneeUniversitaire::class);
    }

    /**
     * Phase 2 : rattachement LMD + pédagogique.
     */
    public function programmeRef(): BelongsTo
    {
        return $this->belongsTo(Programme::class, 'programme_id');
    }

    public function programme(): BelongsTo
    {
        return $this->programmeRef();
    }

    public function niveauRef(): BelongsTo
    {
        return $this->belongsTo(Niveau::class, 'niveau_id');
    }

    public function niveauRelation(): BelongsTo
    {
        return $this->niveauRef();
    }

    public function pedagogiques(): HasMany
    {
        return $this->hasMany(InscriptionPedagogique::class, 'inscription_id');
    }

    public function semestres(): HasMany
    {
        return $this->hasMany(Semestre::class)->orderBy('numero');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    public function semestre(int $numero): ?Semestre
    {
        return $this->semestres->firstWhere('numero', $numero);
    }

    /**
     * Inscription courante : année universitaire la plus récente.
     * Source unique (jointure sur `annees_universitaires.date_debut`).
     */
    public function scopeCourante(Builder $query): Builder
    {
        return $query
            ->join('annees_universitaires', 'annees_universitaires.id', '=', 'inscriptions_academiques.annee_universitaire_id')
            ->orderByDesc('annees_universitaires.date_debut')
            ->select('inscriptions_academiques.*');
    }

    /**
     * Libellé lisible : « 2024-2025 — Licence 2 ».
     */
    public function getLibelleCompletAttribute(): string
    {
        $cycles = ['L' => 'Licence', 'M' => 'Master', 'D' => 'Doctorat'];

        return $this->anneeUniversitaire?->libelle.' — '.($cycles[$this->niveau[0]] ?? $this->niveau).substr($this->niveau, 1);
    }
}
