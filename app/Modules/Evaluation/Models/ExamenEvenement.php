<?php

namespace App\Modules\Evaluation\Models;

use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Journal append-only des transitions d'examen (LOOP EX-LIFECYCLE).
 *
 * Chaque transition effective (ou création) porte l'acteur réel, l'état
 * avant/après et le motif éventuel. Complète `PlanningEvenement` (qui
 * couvre le circuit de gouvernance) sans le dupliquer : ce journal
 * enregistre les transitions simples exécutées par `ExamenService`.
 *
 * Append-only : les lignes ne sont jamais modifiées ni supprimées ;
 * une correction produit une nouvelle ligne.
 */
class ExamenEvenement extends Model
{
    protected $table = 'evaluation_examen_evenements';

    public const UPDATED_AT = null;

    public const ACTION_TRANSITION = 'transition_effectuee';

    public const ACTION_CREATION = 'creation';

    protected $fillable = [
        'examen_id', 'action', 'acteur_id', 'statut_avant', 'statut_apres',
        'motif', 'metadonnees', 'created_at',
    ];

    protected function casts(): array
    {
        return ['metadonnees' => 'array', 'created_at' => 'datetime'];
    }

    public function examen(): BelongsTo
    {
        return $this->belongsTo(Examen::class, 'examen_id');
    }

    public function acteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acteur_id');
    }

    /** Append-only : toute mise à jour est refusée au niveau modèle. */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new DomainException('Le journal des transitions d\'examen est append-only : modification interdite.');
        });

        static::deleting(function () {
            throw new DomainException('Le journal des transitions d\'examen est append-only : suppression interdite.');
        });
    }
}
