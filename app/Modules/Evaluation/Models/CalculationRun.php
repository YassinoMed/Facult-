<?php

namespace App\Modules\Evaluation\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Traçabilité des exécutions de calcul (Phase 14 §14.5) : chaque
 * simulation comme chaque préparation officielle enregistre ses
 * règles, le hash de ses entrées et son résumé — rejouable et
 * auditable sans toucher aux résultats eux-mêmes.
 */
class CalculationRun extends Model
{
    protected $table = 'calculation_runs';

    protected $fillable = [
        'deliberation_id', 'mode', 'regles', 'hash_entrees', 'resume', 'auteur_id',
    ];

    protected function casts(): array
    {
        return ['regles' => 'array', 'resume' => 'array'];
    }

    public function deliberation(): BelongsTo
    {
        return $this->belongsTo(Deliberation::class, 'deliberation_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }
}
