<?php

namespace App\Modules\Referentiel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Type de diplôme configurable (§9) : Licence, Mastère, Ingénieur,
 * Doctorat… — table administrable, jamais codée en dur.
 */
class TypeDiplome extends Model
{
    protected $table = 'type_diplomes';

    /**
     * Cycles LMD de Bologne (catégorie indicative — chaque programme
     * garde ses propres crédits requis).
     */
    public const CYCLES = ['licence', 'master', 'doctorat'];

    protected $fillable = ['code', 'nom', 'ordre', 'actif', 'cycle', 'credits_requis', 'duree_annees'];

    protected function casts(): array
    {
        return ['actif' => 'boolean', 'credits_requis' => 'integer', 'duree_annees' => 'integer'];
    }

    public function programmes(): HasMany
    {
        return $this->hasMany(Programme::class);
    }
}
