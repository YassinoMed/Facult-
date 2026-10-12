<?php

namespace App\Modules\Enseignement\Models;

use App\Models\Matiere;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Composante d'évaluation d'une ECUE (§24) : somme contrôlée = 100.
 */
class ComposanteEvaluation extends Model
{
    protected $table = 'composantes_evaluation';

    protected $fillable = ['matiere_id', 'code', 'libelle', 'pourcentage'];

    protected function casts(): array
    {
        return ['pourcentage' => 'decimal:2'];
    }

    public function matiere(): BelongsTo
    {
        return $this->belongsTo(Matiere::class);
    }
}
