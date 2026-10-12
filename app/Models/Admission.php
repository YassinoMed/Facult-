<?php

namespace App\Models;

use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Admission (roadmap « admission Master ») : acte d'admission d'un
 * étudiant vers un programme/niveau pour une année — source légitime
 * de progression LMD en fin de cycle, consommé une seule fois.
 */
class Admission extends Model
{
    public const DECISIONS = ['admis', 'admis_conditionnel', 'refuse'];

    protected $fillable = [
        'etudiant_id', 'programme_id', 'niveau_id', 'annee_universitaire_id',
        'decision', 'motif', 'decided_by', 'decided_at',
        'consumed_at', 'consumed_inscription_id',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class);
    }

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    public function niveau(): BelongsTo
    {
        return $this->belongsTo(Niveau::class);
    }

    public function anneeUniversitaire(): BelongsTo
    {
        return $this->belongsTo(AnneeUniversitaire::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** Non consommée et non refusée : peut servir de garde de progression. */
    public function estActive(): bool
    {
        return $this->consumed_at === null && $this->decision !== 'refuse';
    }
}
