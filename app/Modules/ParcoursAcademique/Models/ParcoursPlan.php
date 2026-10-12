<?php

namespace App\Modules\ParcoursAcademique\Models;

use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Plan de parcours proposé : objectif diplôme + ECUE/semestres cibles.
 * Proposition réversible, jamais une décision officielle.
 */
class ParcoursPlan extends Model
{
    protected $table = 'parcours_plans';

    public const STATUTS = ['brouillon', 'propose', 'valide', 'archive'];

    protected $fillable = [
        'etudiant_id', 'inscription_source_id', 'objectif',
        'contenu', 'statut', 'version', 'auteur_id',
    ];

    protected function casts(): array
    {
        return ['contenu' => 'array', 'version' => 'integer'];
    }

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class);
    }

    public function inscriptionSource(): BelongsTo
    {
        return $this->belongsTo(InscriptionAcademique::class, 'inscription_source_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }
}
