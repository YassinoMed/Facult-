<?php

namespace App\Modules\Evaluation\Models;

use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Version de planning soumise au circuit de gouvernance (§2.7, §2.8).
 *
 * Une ligne = UNE version soumise. Les lignes antérieures ne sont jamais
 * supprimées ni réécrites pour masquer un rejet, un désaccord ou une
 * publication : chaque transition est également journalisée dans
 * `evaluation_planning_evenements` (append-only).
 */
class PlanningValidation extends Model
{
    protected $table = 'evaluation_planning_validations';

    public const STATUT_SOUMIS = 'soumis';

    public const STATUT_CERTIFIE = 'certifie';

    public const STATUT_APPROUVE = 'approuve';

    public const STATUT_REJETE = 'rejete';

    public const STATUT_ESCALADE = 'escalade';

    public const STATUT_PUBLIE = 'publie';

    protected $fillable = [
        'uuid', 'examen_id', 'version', 'statut', 'empreinte_planning',
        'date_gel_appliquee',
        'prepare_par', 'soumis_at',
        'certifie_par', 'certifie_at',
        'approbateur_1', 'approuve_1_at',
        'approbateur_2', 'approuve_2_at',
        'rejete_par', 'rejete_at', 'motif_rejet',
        'escalade_par', 'escalade_at', 'motif_escalade',
        'escalade_resolue', 'escalade_resolue_par', 'escalade_resolue_at', 'resolution_escalade',
        'publie_par', 'publie_at',
        'exceptionnel', 'motif_exceptionnel',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'soumis_at' => 'datetime',
            'certifie_at' => 'datetime',
            'approuve_1_at' => 'datetime',
            'approuve_2_at' => 'datetime',
            'rejete_at' => 'datetime',
            'escalade_at' => 'datetime',
            'escalade_resolue' => 'boolean',
            'escalade_resolue_at' => 'datetime',
            'publie_at' => 'datetime',
            'date_gel_appliquee' => 'datetime',
            'exceptionnel' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PlanningValidation $validation): void {
            if (empty($validation->uuid)) {
                $validation->uuid = (string) Str::uuid();
            }
        });

        static::deleting(function (): void {
            throw new DomainException(
                'Historique de gouvernance immuable : une version soumise ne peut pas être supprimée.'
            );
        });

        // Une ligne ne peut avancer que vers l'avant : aucune décision
        // déjà prise ne peut être effacée, réattribuée ou réécrite.
        static::updating(function (PlanningValidation $validation): void {
            if (in_array($validation->getOriginal('statut'), [self::STATUT_PUBLIE, self::STATUT_REJETE], true)
                && $validation->isDirty()) {
                throw new DomainException(
                    'Historique de gouvernance immuable : une version publiée ou rejetée ne peut plus être modifiée.'
                );
            }

            foreach (['examen_id', 'version', 'prepare_par', 'soumis_at', 'date_gel_appliquee'] as $fige) {
                if ($validation->isDirty($fige)) {
                    throw new DomainException("Champ de gouvernance figé : « {$fige} » ne peut pas être modifié.");
                }
            }

            foreach (['approbateur_1', 'approbateur_2', 'certifie_par', 'publie_par', 'rejete_par', 'escalade_par'] as $acteur) {
                $original = $validation->getOriginal($acteur);
                if ($original !== null && $validation->isDirty($acteur) && (int) $validation->{$acteur} !== (int) $original) {
                    throw new DomainException("Décision déjà enregistrée : « {$acteur} » ne peut pas être réattribué.");
                }
            }

            foreach (['motif_rejet', 'motif_escalade', 'resolution_escalade'] as $motif) {
                $original = $validation->getOriginal($motif);
                if ($original !== null && $validation->isDirty($motif) && $validation->{$motif} !== $original) {
                    throw new DomainException("Motif déjà enregistré : « {$motif} » ne peut pas être réécrit.");
                }
            }
        });
    }

    public function examen(): BelongsTo
    {
        return $this->belongsTo(Examen::class, 'examen_id');
    }

    public function evenements(): HasMany
    {
        return $this->hasMany(PlanningEvenement::class, 'planning_validation_id')->orderBy('id');
    }

    public function preparateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepare_par');
    }

    public function estPubliee(): bool
    {
        return $this->statut === self::STATUT_PUBLIE;
    }

    public function estApprouvee(): bool
    {
        return $this->approbateur_1 !== null && $this->approbateur_2 !== null;
    }

    public function estBloqueeParEscalade(): bool
    {
        return $this->statut === self::STATUT_ESCALADE && ! $this->escalade_resolue;
    }
}
