<?php

namespace App\Modules\AssuranceQualite\Models;

use App\Models\Faculte;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lot 16 — Constat d'évaluation d'un critère (chaîne critère → preuve →
 * constat → plan d'action).
 *
 * - UN constat par (critère, périmètre) — clé portable `perimetre`
 *   ('global' | 'faculte:{id}') car SQLite traite les NULL comme distincts.
 * - RÈGLE D'OR (interdit du cahier) : un critère SANS constat n'est JAMAIS
 *   conforme par défaut ; l'évaluation `non_evalue` est un constat EXPLICITE
 *   compté comme tel, jamais comme conforme.
 * - Évaluation humaine tracée : évaluateur + date.
 */
class ConstatAccreditation extends Model
{
    protected $table = 'constats_accreditation';

    public const EVALUATIONS = [
        'non_evalue', 'conforme', 'partiellement_conforme', 'non_conforme',
    ];

    /** @var array<int, string> */
    protected $fillable = [
        'critere_id', 'perimetre', 'faculte_id', 'evaluation',
        'commentaire', 'evaluateur_id', 'evalue_le',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['evalue_le' => 'datetime'];
    }

    public static function perimetrePour(?int $faculteId): string
    {
        return $faculteId === null ? 'global' : 'faculte:'.$faculteId;
    }

    public function critere(): BelongsTo
    {
        return $this->belongsTo(CritereAccreditation::class, 'critere_id');
    }

    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class);
    }

    public function evaluateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluateur_id');
    }

    public function preuves(): HasMany
    {
        return $this->hasMany(PreuveConstatAccreditation::class, 'constat_id');
    }

    public function plansAction(): HasMany
    {
        return $this->hasMany(PlanActionAccreditation::class, 'constat_id');
    }
}
