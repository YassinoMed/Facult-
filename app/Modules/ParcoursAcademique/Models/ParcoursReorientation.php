<?php

namespace App\Modules\ParcoursAcademique\Models;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Réorientation : conserve ancien + nouveau parcours, année,
 * décision et justification. Historique précédent préservé
 * (aucune suppression, inscriptions d'origine intactes).
 */
class ParcoursReorientation extends Model
{
    protected $table = 'parcours_reorientations';

    public const STATUTS = ['proposee', 'validee', 'rejetee', 'annulee'];

    protected $fillable = [
        'etudiant_id', 'ancien_programme_id', 'ancien_niveau_id', 'ancien_libelle',
        'nouveau_programme_id', 'nouveau_niveau_id', 'nouveau_libelle',
        'annee_universitaire_id', 'decision', 'justification', 'statut', 'auteur_id',
    ];

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class);
    }

    public function ancienProgramme(): BelongsTo
    {
        return $this->belongsTo(Programme::class, 'ancien_programme_id');
    }

    public function ancienNiveau(): BelongsTo
    {
        return $this->belongsTo(Niveau::class, 'ancien_niveau_id');
    }

    public function nouveauProgramme(): BelongsTo
    {
        return $this->belongsTo(Programme::class, 'nouveau_programme_id');
    }

    public function nouveauNiveau(): BelongsTo
    {
        return $this->belongsTo(Niveau::class, 'nouveau_niveau_id');
    }

    public function annee(): BelongsTo
    {
        return $this->belongsTo(AnneeUniversitaire::class, 'annee_universitaire_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }
}
