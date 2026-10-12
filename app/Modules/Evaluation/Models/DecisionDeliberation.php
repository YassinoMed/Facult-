<?php

namespace App\Modules\Evaluation\Models;

use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DecisionDeliberation extends Model
{
    protected $table = 'evaluation_decisions';

    /** Décisions configurables par règlement — valeurs socle, pas vérité universelle (§22). */
    public const DECISIONS = [
        'admis', 'admis_compensation', 'admis_conditionnel',
        'ajourne', 'rattrapage', 'redouble', 'exclu', 'a_examiner',
    ];

    protected $fillable = [
        'deliberation_id', 'etudiant_id', 'inscription_academique_id',
        'moyenne', 'credits_obtenus', 'credits_total',
        'ues_validees', 'ues_total', 'decision', 'commentaire', 'publie',
    ];

    protected function casts(): array
    {
        return ['moyenne' => 'decimal:2', 'publie' => 'boolean'];
    }

    public function deliberation(): BelongsTo
    {
        return $this->belongsTo(Deliberation::class, 'deliberation_id');
    }

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class);
    }

    public function inscriptionAcademique(): BelongsTo
    {
        return $this->belongsTo(InscriptionAcademique::class, 'inscription_academique_id');
    }
}
