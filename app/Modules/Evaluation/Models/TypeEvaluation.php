<?php

namespace App\Modules\Evaluation\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Référentiel configurable des types d'évaluation (Phase 5 §26) :
 * code stable, libellé, coefficient et durée par défaut. Les épreuves
 * référencent le code — jamais de liste en dur côté planification.
 */
class TypeEvaluation extends Model
{
    protected $table = 'evaluation_types_evaluation';

    protected $fillable = [
        'code', 'libelle', 'coefficient_defaut', 'duree_defaut_minutes', 'actif',
    ];

    protected function casts(): array
    {
        return [
            'coefficient_defaut' => 'decimal:2',
            'actif' => 'boolean',
        ];
    }
}
