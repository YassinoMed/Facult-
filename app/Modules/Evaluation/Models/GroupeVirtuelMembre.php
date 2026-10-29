<?php

namespace App\Modules\Evaluation\Models;

use App\Models\Etudiant;
use App\Modules\Schedule\Models\Group;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Membre d'un groupe virtuel d'examen : décision d'éligibilité tracée
 * pour un étudiant donné. Aucune écriture sur `etudiants.groupe_id` ni
 * sur les inscriptions d'origine.
 */
class GroupeVirtuelMembre extends Model
{
    protected $table = 'evaluation_groupe_virtuel_membres';

    public const DECISION_ELIGIBLE = 'eligible';

    public const DECISION_NON_ELIGIBLE = 'non_eligible';

    public const DECISION_ANOMALIE = 'anomalie';

    public const DECISIONS = [
        self::DECISION_ELIGIBLE,
        self::DECISION_NON_ELIGIBLE,
        self::DECISION_ANOMALIE,
    ];

    protected $fillable = [
        'groupe_virtuel_id', 'etudiant_id', 'groupe_source_id',
        'inscription_academique_id', 'inscription_pedagogique_id',
        'decision', 'regle', 'motif',
    ];

    public function groupeVirtuel(): BelongsTo
    {
        return $this->belongsTo(GroupeVirtuelExamen::class, 'groupe_virtuel_id');
    }

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class);
    }

    public function groupeSource(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'groupe_source_id');
    }

    public function estEligible(): bool
    {
        return $this->decision === self::DECISION_ELIGIBLE;
    }
}
