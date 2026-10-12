<?php

namespace App\Models;

use App\Modules\Schedule\Models\Group;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Refonte affectation §22-23 : proposition par étudiant (groupe
 * recommandé + alternatives + contraintes validées + raison).
 */
class AssignmentItem extends Model
{
    protected $table = 'assignment_items';

    public const STATUTS = ['propose', 'modifie', 'confirme', 'non_affecte', 'annule'];

    protected $fillable = [
        'simulation_id', 'etudiant_id', 'groupe_propose_id', 'alternatives',
        'candidats', 'flexibilite',
        'contraintes_validees', 'conflits', 'raison', 'statut',
    ];

    protected function casts(): array
    {
        return [
            'alternatives' => 'array', 'candidats' => 'array',
            'contraintes_validees' => 'array', 'conflits' => 'array',
            'flexibilite' => 'integer',
        ];
    }

    /**
     * Indicateur de flexibilité (§27, technique uniquement) : nombre
     * de groupes compatibles.
     */
    public static function flexibiliteLabel(int $flexibilite, bool $affecte): string
    {
        if (! $affecte && $flexibilite === 0) {
            return 'Non affectable';
        }

        return match (true) {
            $flexibilite <= 1 => 'Très contraint',
            $flexibilite <= 3 => 'Contraint',
            $flexibilite <= 6 => 'Flexible',
            default => 'Très flexible',
        };
    }

    /** @return BelongsTo<AssignmentSimulation, $this> */
    public function simulation(): BelongsTo
    {
        return $this->belongsTo(AssignmentSimulation::class, 'simulation_id');
    }

    /** @return BelongsTo<Etudiant, $this> */
    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class, 'etudiant_id');
    }

    /** @return BelongsTo<Group, $this> */
    public function groupePropose(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'groupe_propose_id');
    }
}
