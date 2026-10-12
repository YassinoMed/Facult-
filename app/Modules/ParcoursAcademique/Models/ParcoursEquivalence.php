<?php

namespace App\Modules\ParcoursAcademique\Models;

use App\Models\Etudiant;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Referentiel\Models\UniteEnseignement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Équivalence entre matières, UE ou formations. Workflow
 * proposee → validee|rejetee → revoquee. Validée = auditée.
 * N'écrase jamais les notes d'origine (report en simulation seule).
 */
class ParcoursEquivalence extends Model
{
    protected $table = 'parcours_equivalences';

    public const STATUTS = ['proposee', 'validee', 'rejetee', 'revoquee'];

    public const DECISIONS = ['accordee', 'refusee'];

    protected $fillable = [
        'etudiant_id', 'origine_libelle', 'origine_credits',
        'cible_matiere_id', 'cible_ue_id', 'cible_libelle',
        'etablissement', 'decision', 'justification',
        'decide_par', 'decide_le', 'statut', 'auteur_id',
    ];

    protected function casts(): array
    {
        return ['decide_le' => 'datetime'];
    }

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class);
    }

    public function cibleMatiere(): BelongsTo
    {
        return $this->belongsTo(Matiere::class, 'cible_matiere_id');
    }

    public function cibleUe(): BelongsTo
    {
        return $this->belongsTo(UniteEnseignement::class, 'cible_ue_id');
    }

    public function decideur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decide_par');
    }
}
