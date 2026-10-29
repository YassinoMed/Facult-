<?php

namespace App\Modules\AssuranceQualite\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lot 16 — Référentiel d'accréditation (identité).
 *
 * Le catalogue est VIDE par défaut : l'organisme accréditeur et ses
 * référentiels sont SAISIS par l'administration — inventer des critères
 * d'accréditation est interdit par le cahier (LOOP-00).
 */
class ReferentielAccreditation extends Model
{
    protected $table = 'referentiels_accreditation';

    /** @var array<int, string> */
    protected $fillable = ['code', 'nom', 'organisme', 'description', 'cree_par'];

    public function versions(): HasMany
    {
        return $this->hasMany(VersionReferentielAccreditation::class, 'referentiel_id');
    }

    public function creePar()
    {
        return $this->belongsTo(User::class, 'cree_par');
    }
}
