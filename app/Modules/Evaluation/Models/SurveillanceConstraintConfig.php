<?php

namespace App\Modules\Evaluation\Models;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Configuration des contraintes d'affectation des surveillances,
 * contextualisée (système / année / faculté+année / session).
 * Les valeurs HARD de sécurité ne sont pas représentées ici :
 * elles ne sont jamais configurables.
 */
class SurveillanceConstraintConfig extends Model
{
    protected $table = 'surveillance_constraint_configs';

    public const MODES_LIMITE = ['souple', 'strict'];

    protected $fillable = [
        'faculte_id',
        'annee_universitaire_id',
        'session_examen_id',
        'min_surveillants',
        'min_par_salle',
        'ratio_etudiants',
        'seuil_grande_salle',
        'min_surveillants_grande_salle',
        'nb_gardes',
        'max_surveillants',
        'max_jours',
        'mode_limite',
        'poids_charge_totale',
        'poids_charge_jour',
        'poids_gardes',
        'penalite_depassement_jours',
        'penalite_dispersion',
        'penalite_nouveau_jour',
        'poids_surveillance_active',
        'poids_garde_reserve',
        'actif_charge_totale',
        'actif_charge_jour',
        'actif_gardes',
        'actif_limite_jours',
        'actif_dispersion',
        'actif_nouveau_jour',
        'actif_charge_enseignement',
        'poids_charge_enseignement',
        'mode_calcul_charge_enseignement',
        'seuil_surcharge_enseignement',
        'max_surveillances_enseignant',
        'version',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'min_surveillants' => 'integer',
            'min_par_salle' => 'integer',
            'ratio_etudiants' => 'integer',
            'seuil_grande_salle' => 'integer',
            'min_surveillants_grande_salle' => 'integer',
            'nb_gardes' => 'integer',
            'max_surveillants' => 'integer',
            'max_jours' => 'integer',
            'poids_surveillance_active' => 'decimal:2',
            'poids_garde_reserve' => 'decimal:2',
            'actif_charge_totale' => 'boolean',
            'poids_charge_totale' => 'decimal:2',
            'actif_charge_jour' => 'boolean',
            'poids_charge_jour' => 'decimal:2',
            'actif_gardes' => 'boolean',
            'poids_gardes' => 'decimal:2',
            'actif_limite_jours' => 'boolean',
            'penalite_depassement_jours' => 'decimal:2',
            'actif_dispersion' => 'boolean',
            'penalite_dispersion' => 'decimal:2',
            'actif_nouveau_jour' => 'boolean',
            'penalite_nouveau_jour' => 'decimal:2',
            'actif_charge_enseignement' => 'boolean',
            'poids_charge_enseignement' => 'decimal:2',
            'mode_calcul_charge_enseignement' => 'string',
            'seuil_surcharge_enseignement' => 'decimal:1',
            'max_surveillances_enseignant' => 'integer',
            'version' => 'integer',
        ];
    }

    public static function valeursParDefaut(): array
    {
        return [
            'min_surveillants' => 2,
            'min_par_salle' => 1,
            'ratio_etudiants' => 50,
            'seuil_grande_salle' => null,
            'min_surveillants_grande_salle' => null,
            'nb_gardes' => 1,
            'max_surveillants' => null,
            'poids_surveillance_active' => 1.0,
            'poids_garde_reserve' => 0.5,
            'max_jours' => 3,
            'mode_limite' => 'souple',
            'actif_charge_totale' => true,
            'poids_charge_totale' => 10.0,
            'actif_charge_jour' => true,
            'poids_charge_jour' => 15.0,
            'actif_gardes' => true,
            'poids_gardes' => 8.0,
            'actif_limite_jours' => true,
            'penalite_depassement_jours' => 35.0,
            'actif_dispersion' => true,
            'penalite_dispersion' => 10.0,
            'actif_nouveau_jour' => true,
            'penalite_nouveau_jour' => 2.0,
            'actif_charge_enseignement' => false,
            'poids_charge_enseignement' => 5.0,
            'mode_calcul_charge_enseignement' => 'heures_brutes',
            'seuil_surcharge_enseignement' => null,
            'max_surveillances_enseignant' => null,
        ];
    }

    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class);
    }

    public function anneeUniversitaire(): BelongsTo
    {
        return $this->belongsTo(AnneeUniversitaire::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(SessionExamen::class);
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function modificateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(SurveillanceConstraintRevision::class, 'config_id')->orderByDesc('version');
    }

    public function portee(): string
    {
        if ($this->session_examen_id !== null) {
            return 'session';
        }

        if ($this->faculte_id !== null) {
            return 'faculte-annee';
        }

        if ($this->annee_universitaire_id !== null) {
            return 'annee';
        }

        return 'systeme';
    }
}
