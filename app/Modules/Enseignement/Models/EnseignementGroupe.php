<?php

namespace App\Modules\Enseignement\Models;

use App\Modules\Schedule\Models\Group;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot enseignement ↔ groupe (+ sous-groupe optionnel) (§13-14).
 */
class EnseignementGroupe extends Model
{
    protected $table = 'enseignement_groupe';

    protected $fillable = ['enseignement_id', 'groupe_id', 'sous_groupe_id'];

    public function enseignement(): BelongsTo
    {
        return $this->belongsTo(Enseignement::class);
    }

    public function groupe(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function sousGroupe(): BelongsTo
    {
        return $this->belongsTo(SousGroupe::class, 'sous_groupe_id');
    }
}
