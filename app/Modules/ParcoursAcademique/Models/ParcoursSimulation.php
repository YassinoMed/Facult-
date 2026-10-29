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

    /**
     * Libellés d'affichage des scénarios — partagés back-office et
     * portail étudiant : une seule source de libellés, jamais de
     * divergence de présentation entre les deux espaces.
     */
    public const LIBELLES = [
        'passage' => 'Passage',
        'redoublement' => 'Redoublement',
        'conditionnel' => 'Admission conditionnelle',
        'conservation_dettes' => 'Conservation des dettes',
        'reorientation' => 'Réorientation',
        'changement_parcours' => 'Changement de parcours',
        'admission_formation' => 'Admission en formation',
        'passerelle_master' => 'Passerelle Master',
        'validation_semestre' => 'Validation de semestre',
        'compensation' => 'Compensation',
        'validation_matiere' => 'Validation de matière',
        'equivalence' => 'Équivalence',
        'dispense' => 'Dispense',
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
