<?php

namespace App\Modules\ParcoursAcademique\Models;

use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alerte de risque académique : calculée, explicable, informative.
 * Ne prend jamais de décision administrative automatique.
 */
class ParcoursAlerteRisque extends Model
{
    protected $table = 'parcours_alertes_risque';

    public const NIVEAUX = ['faible', 'moyen', 'eleve', 'critique'];

    public const STATUTS = ['nouvelle', 'vue', 'accompagnee', 'cloturee'];

    protected $fillable = [
        'etudiant_id', 'inscription_id', 'niveau', 'score',
        'motifs', 'calcule_le', 'vu_par', 'vu_le', 'statut',
    ];

    protected function casts(): array
    {
        return ['motifs' => 'array', 'calcule_le' => 'datetime', 'vu_le' => 'datetime'];
    }

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class);
    }

    public function inscription(): BelongsTo
    {
        return $this->belongsTo(InscriptionAcademique::class, 'inscription_id');
    }

    public function lecteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vu_par');
    }
}
