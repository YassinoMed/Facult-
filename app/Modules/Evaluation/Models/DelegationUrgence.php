<?php

namespace App\Modules\Evaluation\Models;

use App\Models\Faculte;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Mandat de suppléance d'urgence (§2.11, §2.12).
 *
 * Désignation PRÉALABLE, bornée dans le temps et en actions, révocable et
 * intégralement tracée. Un mandat ne donne jamais un blanc-seing : toute
 * action hors de la liste fermée `actions` est refusée côté service.
 */
class DelegationUrgence extends Model
{
    protected $table = 'evaluation_delegations_urgence';

    public const STATUT_ACTIVE = 'active';

    public const STATUT_REVOQUEE = 'revolue';

    public const STATUT_EXPIREE = 'expiree';

    /** Liste FERMÉE des actions d'urgence délégables. */
    public const ACTIONS_AUTORISEES = [
        'mesure_urgence',
        'resolution_incident',
        'validation_presence',
        'convocation_urgence',
        'report_epreuve',
    ];

    /** Périmètres nommés → actions réellement autorisées. */
    public const PERIMETRES = [
        'validation_urgences_jour_j' => ['mesure_urgence', 'resolution_incident'],
        'presence_jour_j' => ['validation_presence'],
        'logistique_jour_j' => ['convocation_urgence', 'report_epreuve'],
    ];

    protected $fillable = [
        'uuid', 'delegant_id', 'delegue_id', 'faculte_id', 'session_id',
        'actions', 'debut_at', 'fin_at', 'motif', 'statut',
        'revoquee_at', 'revoquee_par', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'actions' => 'array',
            'debut_at' => 'datetime',
            'fin_at' => 'datetime',
            'revoquee_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (DelegationUrgence $delegation): void {
            if (empty($delegation->uuid)) {
                $delegation->uuid = (string) Str::uuid();
            }
        });

        static::deleting(function (): void {
            throw new DomainException(
                'Traçabilité de suppléance immuable : un mandat ne peut pas être supprimé.'
            );
        });

        static::updating(function (DelegationUrgence $delegation): void {
            $statutOriginal = $delegation->getOriginal('statut');

            if (in_array($statutOriginal, [self::STATUT_REVOQUEE, self::STATUT_EXPIREE], true)
                && $delegation->isDirty()) {
                throw new DomainException(
                    'Traçabilité de suppléance immuable : un mandat révoqué ou expiré ne peut plus être modifié.'
                );
            }

            foreach (['delegant_id', 'delegue_id', 'actions', 'debut_at', 'fin_at'] as $fige) {
                if ($delegation->getOriginal($fige) !== null && $delegation->isDirty($fige)) {
                    throw new DomainException("Mandat de suppléance figé : « {$fige} » ne peut pas être modifié.");
                }
            }
        });
    }

    public function delegant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegant_id');
    }

    public function delegue(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegue_id');
    }

    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class, 'faculte_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(SessionExamen::class, 'session_id');
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function revoquePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoquee_par');
    }

    public function estRevoquee(): bool
    {
        return $this->statut === self::STATUT_REVOQUEE;
    }

    public function estDansLaFenetre(?Carbon $instant = null): bool
    {
        $instant ??= Carbon::now();

        return $instant->greaterThanOrEqualTo($this->debut_at)
            && $instant->lessThanOrEqualTo($this->fin_at);
    }

    public function estActive(?Carbon $instant = null): bool
    {
        return $this->statut === self::STATUT_ACTIVE && $this->estDansLaFenetre($instant);
    }

    public function actionAutorisee(string $action): bool
    {
        return in_array($action, (array) $this->actions, true);
    }
}
