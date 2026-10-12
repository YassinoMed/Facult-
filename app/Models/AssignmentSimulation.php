<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Refonte affectation §4/§50 : simulation persistée (objet technique,
 * jamais une affectation réelle). Statuts : brouillon → simulee →
 * partiellement_confirmee → confirmee | expiree | annulee.
 */
class AssignmentSimulation extends Model
{
    protected $table = 'assignment_simulations';

    public const STATUTS = ['brouillon', 'simulee', 'partiellement_confirmee', 'confirmee', 'expiree', 'annulee'];

    protected $fillable = [
        'reference', 'statut', 'annee_universitaire_id', 'parametres', 'hash_entrees',
        'seed', 'simulation_origine_id',
        'version', 'candidats', 'eligibles', 'affectes', 'non_affectes', 'conflits',
        'metriques', 'groupes_confirmes', 'auteur_id',
    ];

    protected function casts(): array
    {
        return [
            'parametres' => 'array', 'metriques' => 'array',
            'groupes_confirmes' => 'array',
        ];
    }

    /** @return HasMany<AssignmentItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(AssignmentItem::class, 'simulation_id');
    }

    public function groupeConfirme(int $groupeId): bool
    {
        return array_key_exists((string) $groupeId, $this->groupes_confirmes ?? []);
    }
}
