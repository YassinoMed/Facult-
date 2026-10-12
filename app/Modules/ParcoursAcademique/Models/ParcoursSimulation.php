<?php

namespace App\Modules\ParcoursAcademique\Models;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Simulation de parcours : append-only, explicable, réversible.
 * Ne modifie jamais notes, crédits, niveau ni décision officielle.
 */
class ParcoursSimulation extends Model
{
    protected $table = 'parcours_simulations';

    public const SCENARIOS = [
        'passage', 'redoublement', 'conditionnel', 'conservation_dettes',
        'reorientation', 'changement_parcours', 'admission_formation',
        'passerelle_master', 'validation_semestre', 'compensation',
        'validation_matiere', 'equivalence', 'dispense',
    ];

    protected $fillable = [
        'etudiant_id', 'inscription_source_id', 'annee_universitaire_id',
        'scenario', 'hypotheses', 'resultat', 'regle_reference', 'auteur_id',
    ];

    protected function casts(): array
    {
        return ['hypotheses' => 'array', 'resultat' => 'array'];
    }

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class);
    }

    public function inscriptionSource(): BelongsTo
    {
        return $this->belongsTo(InscriptionAcademique::class, 'inscription_source_id');
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
