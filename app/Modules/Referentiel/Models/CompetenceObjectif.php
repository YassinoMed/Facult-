<?php

namespace App\Modules\Referentiel\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Association canonique programme/UE/ECUE ↔ compétence (Lot 20).
 * `cible_type` ∈ {programme, ue, ecue} désigne une entité CANONIQUE du
 * référentiel (Programme, UniteEnseignement, Matiere) — l'existence de
 * la cible est vérifiée par le service à l'association et à l'import ;
 * aucune version de maquette n'est visée : les rapports de couverture
 * croisent le plan d'UNE maquette avec ces associations canoniques.
 */
class CompetenceObjectif extends Model
{
    public const TYPES_CIBLE = ['programme', 'ue', 'ecue'];

    protected $fillable = [
        'competence_id', 'cible_type', 'cible_id',
        'niveau_attendu', 'commentaire', 'cree_par',
    ];

    public function competence(): BelongsTo
    {
        return $this->belongsTo(Competence::class);
    }

    public function creePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }
}
