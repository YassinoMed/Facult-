<?php

namespace App\Modules\ParcoursAcademique\Models;

use App\Models\Etudiant;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Passerelle (Licence → Master ou inter-formations) : éligibilité
 * analysée sur diplôme, crédits, résultats, prérequis et décision
 * d'admission. Jamais de passage automatique L3 → M1.
 */
class ParcoursPasserelle extends Model
{
    protected $table = 'parcours_passerelles';

    public const STATUTS = ['proposee', 'eligible', 'non_eligible', 'admise', 'refusee'];

    protected $fillable = [
        'etudiant_id', 'programme_origine_id', 'niveau_origine_id',
        'programme_cible_id', 'niveau_cible_id',
        'credits_obtenus', 'moyenne', 'conditions',
        'decision_admission', 'justification', 'statut', 'auteur_id',
    ];

    protected function casts(): array
    {
        return ['conditions' => 'array', 'moyenne' => 'decimal:2'];
    }

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class);
    }

    public function programmeOrigine(): BelongsTo
    {
        return $this->belongsTo(Programme::class, 'programme_origine_id');
    }

    public function niveauOrigine(): BelongsTo
    {
        return $this->belongsTo(Niveau::class, 'niveau_origine_id');
    }

    public function programmeCible(): BelongsTo
    {
        return $this->belongsTo(Programme::class, 'programme_cible_id');
    }

    public function niveauCible(): BelongsTo
    {
        return $this->belongsTo(Niveau::class, 'niveau_cible_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }
}
