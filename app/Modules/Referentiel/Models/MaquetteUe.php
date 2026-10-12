<?php

namespace App\Modules\Referentiel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ligne UE d'une maquette (pivot `maquette_ue` promu en modèle pour
 * porter les contextes ECUE annuels).
 */
class MaquetteUe extends Model
{
    protected $table = 'maquette_ue';

    protected $fillable = [
        'maquette_id', 'ue_id', 'semestre_id',
    ];

    public function maquette(): BelongsTo
    {
        return $this->belongsTo(Maquette::class, 'maquette_id');
    }

    public function ue(): BelongsTo
    {
        return $this->belongsTo(UniteEnseignement::class, 'ue_id');
    }

    public function semestre(): BelongsTo
    {
        return $this->belongsTo(SemestreLmd::class, 'semestre_id');
    }

    public function ecues(): HasMany
    {
        return $this->hasMany(MaquetteUeEcue::class, 'maquette_ue_id');
    }
}
