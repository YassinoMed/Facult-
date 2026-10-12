<?php

namespace App\Modules\ParcoursAcademique\Models;

use App\Models\AnneeUniversitaire;
use App\Modules\Referentiel\Models\Programme;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Règle académique configurable versionnée du parcours LMD.
 * Complète RegleEvaluation (seuils notes) : passage, crédits,
 * admission Master, risques. Anciennes décisions restent lisibles
 * via code+version figés dans les simulations.
 */
class ParcoursRegle extends Model
{
    protected $table = 'parcours_regles';

    protected $fillable = [
        'code', 'libelle', 'version', 'actif',
        'annee_universitaire_id', 'programme_id', 'criteres',
    ];

    protected function casts(): array
    {
        return ['actif' => 'boolean', 'criteres' => 'array', 'version' => 'integer'];
    }

    public function annee(): BelongsTo
    {
        return $this->belongsTo(AnneeUniversitaire::class, 'annee_universitaire_id');
    }

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }
}
