<?php

namespace App\Modules\Evaluation\Models;

use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Journal append-only des transitions de gouvernance (§3.3).
 *
 * Chaque action porte l'acteur réel, son rôle au moment de l'action,
 * l'empreinte du planning, l'état avant/après et le motif. Les lignes
 * ne sont jamais modifiées ni supprimées : une correction produit une
 * nouvelle ligne.
 */
class PlanningEvenement extends Model
{
    protected $table = 'evaluation_planning_evenements';

    public const UPDATED_AT = null;

    public const ACTION_SOUMIS = 'soumis';

    public const ACTION_CERTIFIE = 'certifie';

    public const ACTION_APPROUVE_1 = 'approuve_1';

    public const ACTION_APPROUVE_2 = 'approuve_2';

    public const ACTION_APPROUVE = 'approuve';

    public const ACTION_REJETE = 'rejete';

    public const ACTION_ESCALADE = 'escalade';

    public const ACTION_ESCALADE_RESOLUE = 'escalade_resolue';

    public const ACTION_PUBLIE = 'publie';

    public const ACTION_VERSION_CREEE = 'version_creee';

    protected $fillable = [
        'planning_validation_id', 'examen_id', 'version', 'action', 'acteur_id',
        'role_contexte', 'motif', 'empreinte_planning', 'avant', 'apres', 'created_at',
    ];

    protected function casts(): array
    {
        return ['avant' => 'array', 'apres' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        $interdire = function (): void {
            throw new DomainException('Journal d’audit immuable : aucune modification ni suppression n’est autorisée.');
        };

        static::updating($interdire);
        static::deleting($interdire);
    }

    public function validation(): BelongsTo
    {
        return $this->belongsTo(PlanningValidation::class, 'planning_validation_id');
    }

    public function acteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acteur_id');
    }
}
