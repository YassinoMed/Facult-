<?php

namespace App\Modules\Enseignement\Models;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\GradeEnseignant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Seuil de service configurable par grade/faculté/année (§20).
 */
class SeuilService extends Model
{
    protected $table = 'seuils_service';

    protected $fillable = [
        'grade_id', 'faculte_id', 'annee_universitaire_id',
        'service_min', 'service_cible', 'service_max',
    ];

    protected function casts(): array
    {
        return [
            'service_min' => 'decimal:1', 'service_cible' => 'decimal:1',
            'service_max' => 'decimal:1',
        ];
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(GradeEnseignant::class);
    }

    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class);
    }

    public function anneeUniversitaire(): BelongsTo
    {
        return $this->belongsTo(AnneeUniversitaire::class);
    }
}
