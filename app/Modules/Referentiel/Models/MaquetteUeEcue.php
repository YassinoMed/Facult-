<?php

namespace App\Modules\Referentiel\Models;

use App\Models\Matiere;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contexte annuel d'un ECUE dans une maquette (LOOP référentiel §6B) :
 * valeurs propres à l'année (coefficient, crédits, volumes, évaluation,
 * régime) + règles Master (obligatoire, groupe de choix, conditions) —
 * sans jamais muter la matière canonique (`matieres`).
 */
class MaquetteUeEcue extends Model
{
    protected $table = 'maquette_ue_ecue';

    protected $fillable = [
        'maquette_ue_id',
        'matiere_id',
        'ordre',
        'coefficient',
        'credits',
        'volume_cm',
        'volume_td',
        'volume_tp',
        'type_evaluation',
        'regime',
        'obligatoire',
        'groupe_choix',
        'conditions',
    ];

    protected function casts(): array
    {
        return [
            'coefficient' => 'decimal:2',
            'obligatoire' => 'boolean',
            'conditions' => 'array',
        ];
    }

    public function maquetteUe(): BelongsTo
    {
        return $this->belongsTo(MaquetteUe::class, 'maquette_ue_id');
    }

    public function matiere(): BelongsTo
    {
        return $this->belongsTo(Matiere::class, 'matiere_id');
    }
}
