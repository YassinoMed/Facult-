<?php

namespace App\Modules\Evaluation\Models;

use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Demande de régularisation de présence (§2.9).
 *
 * Une demande est déposée par un acteur (jamais anonyme) puis instruite par
 * une personne DISTINCTE. La ligne initiale n'est jamais supprimée : une
 * décision est un passage à l'état final, pas un remplacement silencieux.
 */
class RegularisationPresence extends Model
{
    protected $table = 'evaluation_regularisations_presence';

    public const STATUT_EN_ATTENTE = 'en_attente';

    public const STATUT_APPROUVEE = 'approuvee';

    public const STATUT_REJETEE = 'rejetee';

    /** Décisions autorisées et statut final correspondant. */
    public const DECISIONS = [
        'absent_justifie' => self::STATUT_APPROUVEE,
        'present' => self::STATUT_APPROUVEE,
        'absent_injustifie' => self::STATUT_REJETEE,
    ];

    protected $fillable = [
        'uuid', 'candidat_id', 'statut', 'statut_initial', 'statut_final',
        'motif', 'preuve_reference', 'demande_par', 'demande_at',
        'decide_par', 'decide_at', 'motif_decision',
    ];

    protected function casts(): array
    {
        return [
            'demande_at' => 'datetime',
            'decide_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (RegularisationPresence $regularisation): void {
            if (empty($regularisation->uuid)) {
                $regularisation->uuid = (string) Str::uuid();
            }
        });

        static::deleting(function (): void {
            throw new DomainException(
                'Historique de régularisation immuable : une demande de présence ne peut pas être supprimée.'
            );
        });

        // Une décision déjà rendue ne peut plus être réécrite ni réattribuée.
        static::updating(function (RegularisationPresence $regularisation): void {
            $original = $regularisation->getOriginal('statut');

            if (in_array($original, [self::STATUT_APPROUVEE, self::STATUT_REJETEE], true)
                && $regularisation->isDirty()) {
                throw new DomainException(
                    'Historique de régularisation immuable : une décision rendue ne peut plus être modifiée.'
                );
            }

            foreach (['candidat_id', 'statut_initial', 'demande_par', 'demande_at', 'motif'] as $fige) {
                if ($regularisation->getOriginal($fige) !== null && $regularisation->isDirty($fige)) {
                    throw new DomainException("Champ de régularisation figé : « {$fige} » ne peut pas être modifié.");
                }
            }
        });
    }

    public function candidat(): BelongsTo
    {
        return $this->belongsTo(Candidat::class, 'candidat_id');
    }

    public function demandeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'demande_par');
    }

    public function decideur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decide_par');
    }

    public function estEnAttente(): bool
    {
        return $this->statut === self::STATUT_EN_ATTENTE;
    }

    public function aEteDecidee(): bool
    {
        return in_array($this->statut, [self::STATUT_APPROUVEE, self::STATUT_REJETEE], true);
    }
}
