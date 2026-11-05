<?php

namespace App\Models\Iam;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Décision d'approbation individuelle. L'unicité (demande, approbateur)
 * est garantie par contrainte de base : un acteur ne peut pas compter
 * deux fois pour atteindre le quorum.
 */
class ApprobationIam extends Model
{
    public const DECISION_APPROUVE = 'approuve';

    public const DECISION_REJETE = 'rejete';

    protected $table = 'iam_approbations';

    /** @var array<int, string> */
    protected $guarded = [];

    protected function casts(): array
    {
        return ['decide_le' => 'datetime'];
    }

    public function demande(): BelongsTo
    {
        return $this->belongsTo(DemandeIam::class, 'demande_id');
    }

    public function approbateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approbateur_id');
    }
}
