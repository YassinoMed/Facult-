<?php

namespace App\Modules\Evaluation\Models;

use App\Models\AnneeUniversitaire;
use App\Modules\Evaluation\Services\RegleEvaluationService;
use App\Modules\Referentiel\Models\Programme;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegleEvaluation extends Model
{
    protected $table = 'evaluation_regles';

    public const ARRONDIS = ['2dec', '1dec', 'entier'];

    protected static function booted(): void
    {
        static::saved(fn () => RegleEvaluationService::flushCache());
        static::deleted(fn () => RegleEvaluationService::flushCache());
    }

    protected $fillable = [
        'annee_universitaire_id', 'programme_id', 'seuil_validation', 'seuil_rattrapage',
        'note_eliminatoire', 'compensation_ecue', 'compensation_ue', 'compensation_semestre',
        'arrondi', 'version', 'actif',
    ];

    protected function casts(): array
    {
        return [
            'seuil_validation' => 'decimal:2',
            'seuil_rattrapage' => 'decimal:2',
            'note_eliminatoire' => 'decimal:2',
            'compensation_ecue' => 'boolean',
            'compensation_ue' => 'boolean',
            'compensation_semestre' => 'boolean',
            'actif' => 'boolean',
        ];
    }

    public function anneeUniversitaire(): BelongsTo
    {
        return $this->belongsTo(AnneeUniversitaire::class);
    }

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    /** Arrondi centralisé : le même calcul produit le même résultat partout (§14). */
    public function arrondir(?float $valeur): ?float
    {
        if ($valeur === null) {
            return null;
        }

        return match ($this->arrondi) {
            '1dec' => round($valeur, 1),
            'entier' => (float) round($valeur),
            default => round($valeur, 2),
        };
    }
}
