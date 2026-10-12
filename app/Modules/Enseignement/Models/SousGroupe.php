<?php

namespace App\Modules\Enseignement\Models;

use App\Modules\Schedule\Models\Group;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sous-groupe pédagogique TD/TP rattaché à un groupe principal (§15).
 */
class SousGroupe extends Model
{
    protected $table = 'sous_groupes';

    protected $fillable = ['groupe_id', 'code', 'nom', 'type', 'capacite', 'actif'];

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    public function groupe(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function enseignements(): HasMany
    {
        return $this->hasMany(EnseignementGroupe::class, 'sous_groupe_id');
    }
}
