<?php

namespace App\Modules\AssuranceQualite\Models;

use App\Models\Faculte;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lot 16 — Critère d'accréditation porté par une VERSION de référentiel.
 *
 * - `faculte_id` NULL = portée globale ; sinon le critère ne s'applique
 *   qu'à cette faculté (périmètre d'évaluation explicite).
 * - Le contenu du critère est SAISI (jamais seedé, jamais inventé).
 * - Éditable UNIQUEMENT tant que la version est `brouillon` : une version
 *   approuvée est FIGÉE (garde contrôleur + service).
 */
class CritereAccreditation extends Model
{
    protected $table = 'criteres_accreditation';

    /** @var array<int, string> */
    protected $fillable = [
        'version_referentiel_id', 'code', 'libelle', 'description', 'faculte_id',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(VersionReferentielAccreditation::class, 'version_referentiel_id');
    }

    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class);
    }

    public function constats(): HasMany
    {
        return $this->hasMany(ConstatAccreditation::class, 'critere_id');
    }
}
