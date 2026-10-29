<?php

namespace App\Modules\Schedule\Models;

use App\Models\AffectationGroupe;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Modules\Enseignement\Models\EnseignementGroupe;
use App\Modules\Enseignement\Models\SousGroupe;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Module Schedule : groupe/classe d'étudiants.
 */
class Group extends Model
{
    protected $table = 'groups';

    protected $fillable = [
        'code', 'nom', 'faculte_id', 'niveau', 'filiere', 'actif',
        // Phase 2 (additif, nullable) : rattachement LMD + capacité (§15).
        'programme_id', 'niveau_id', 'annee_universitaire_id', 'capacite',
        'capacite_illimitee',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['actif' => 'boolean', 'capacite_illimitee' => 'boolean'];
    }

    /**
     * @return BelongsTo<Faculte, $this>
     */
    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class);
    }

    /**
     * @return HasMany<Etudiant, $this>
     */
    public function etudiants(): HasMany
    {
        return $this->hasMany(Etudiant::class, 'groupe_id');
    }

    /**
     * @return HasMany<Schedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class, 'groupe_id');
    }

    /**
     * Phase 2 : rattachement LMD + affectations historisées.
     */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    public function niveauRef(): BelongsTo
    {
        return $this->belongsTo(Niveau::class, 'niveau_id');
    }

    public function anneeUniversitaire(): BelongsTo
    {
        return $this->belongsTo(AnneeUniversitaire::class);
    }

    public function affectations(): HasMany
    {
        return $this->hasMany(AffectationGroupe::class, 'groupe_id');
    }

    /**
     * Phase 3 : enseignements rattachés (pivot + sous-groupe).
     */
    public function enseignementLiens(): HasMany
    {
        return $this->hasMany(EnseignementGroupe::class, 'groupe_id');
    }

    /**
     * Sous-groupes TD/TP rattachés à ce groupe.
     *
     * @return HasMany<SousGroupe, $this>
     */
    public function sousGroupes(): HasMany
    {
        return $this->hasMany(SousGroupe::class, 'groupe_id');
    }

    /**
     * Effectif actuel (affectations actives).
     */
    public function effectifActuel(): int
    {
        return $this->affectations()->where('statut', 'active')->count();
    }

    /**
     * Capacité atteinte (null = illimitée — règle historique conservée ;
     * voir modeCapacite() pour le triptyque documenté).
     */
    public function estComplet(): bool
    {
        return $this->capacite !== null && $this->effectifActuel() >= (int) $this->capacite;
    }

    /**
     * Triptyque Data Mesh des capacités (lecture seule, sans effet moteur) :
     * DEFINIE (capacité opposable), ILLIMITEE (décision métier explicite),
     * INCONNUE (NULL sans décision — à confirmer, jamais inventée).
     */
    public function modeCapacite(): string
    {
        if ($this->capacite !== null) {
            return 'DEFINIE';
        }

        return (bool) ($this->capacite_illimitee ?? false) ? 'ILLIMITEE' : 'INCONNUE';
    }
}
