<?php

namespace App\Modules\AssuranceQualite\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lot 16 — Plan d'action correctif relié à un constat.
 *
 * Mesure SAISIE par l'agent qualité (jamais générée automatiquement) ;
 * responsable et échéance optionnels (aucune politique institutionnelle
 * de délai inventée) ; clôture TRACÉE (auteur + date).
 */
class PlanActionAccreditation extends Model
{
    protected $table = 'plans_action_accreditation';

    public const STATUTS = ['ouvert', 'termine'];

    /** @var array<int, string> */
    protected $fillable = [
        'constat_id', 'mesure', 'responsable_id', 'echeance',
        'statut', 'cloture_par', 'cloture_le',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['echeance' => 'date', 'cloture_le' => 'datetime'];
    }

    public function constat(): BelongsTo
    {
        return $this->belongsTo(ConstatAccreditation::class, 'constat_id');
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    public function cloturePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cloture_par');
    }
}
