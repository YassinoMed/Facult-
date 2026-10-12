<?php

namespace App\Modules\Referentiel\Models;

use App\Models\Faculte;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Département rattaché à un établissement (§4).
 */
class Departement extends Model
{
    protected $fillable = [
        'faculte_id', 'code', 'nom', 'description', 'responsable', 'actif',
    ];

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class);
    }

    public function programmes(): HasMany
    {
        return $this->hasMany(Programme::class);
    }
}
