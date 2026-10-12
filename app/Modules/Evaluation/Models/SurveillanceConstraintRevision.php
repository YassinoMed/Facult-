<?php

namespace App\Modules\Evaluation\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Révision append-only d'une configuration de contraintes (§16/§21) :
 * qui, quand, quelles valeurs. Traçabilité sans PII (ids + chiffres).
 */
class SurveillanceConstraintRevision extends Model
{
    protected $table = 'surveillance_constraint_revisions';

    public const UPDATED_AT = null;

    protected $fillable = [
        'config_id',
        'version',
        'params',
        'auteur_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function config(): BelongsTo
    {
        return $this->belongsTo(SurveillanceConstraintConfig::class, 'config_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }
}
