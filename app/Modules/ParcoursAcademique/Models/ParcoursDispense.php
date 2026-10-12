<?php

namespace App\Modules\ParcoursAcademique\Models;

use App\Models\Etudiant;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Referentiel\Models\UniteEnseignement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dispense : ne modifie jamais l'historique original des notes.
 * Prise en compte uniquement en simulation/plan, décision tracée.
 */
class ParcoursDispense extends Model
{
    protected $table = 'parcours_dispenses';

    public const STATUTS = ['proposee', 'validee', 'rejetee', 'revoquee'];

    protected $fillable = [
        'etudiant_id', 'matiere_id', 'ue_id', 'cible_libelle',
        'motif', 'decision', 'responsable',
        'date_debut', 'date_fin', 'statut', 'auteur_id',
    ];

    protected function casts(): array
    {
        return ['date_debut' => 'date', 'date_fin' => 'date'];
    }

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class);
    }

    public function matiere(): BelongsTo
    {
        return $this->belongsTo(Matiere::class);
    }

    public function ue(): BelongsTo
    {
        return $this->belongsTo(UniteEnseignement::class, 'ue_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }
}
