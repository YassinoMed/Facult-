<?php

namespace App\Modules\Evaluation\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * §2.15 — dossier persistant d'un écart de réconciliation tri-sources.
 *
 * L'écart n'est jamais « résolu » par simple changement de statut : il est
 * instruit par un vérificateur puis validé par une personne DISTINCTE. La
 * validation applique le statut retenu au candidat et conserve l'instantané
 * des sources tel qu'il était au moment de la détection.
 */
class EvaluationReconciliationEcart extends Model
{
    protected $table = 'evaluation_reconciliation_ecarts';

    public const STATUT_OUVERT = 'ouvert';

    public const STATUT_INSTUIT = 'instruit';

    public const STATUT_VALIDE = 'valide';

    public const STATUTS = [
        self::STATUT_OUVERT,
        self::STATUT_INSTUIT,
        self::STATUT_VALIDE,
    ];

    public const LIBELLES_STATUTS = [
        self::STATUT_OUVERT => 'Ouvert',
        self::STATUT_INSTUIT => 'Instruit — en attente de validation',
        self::STATUT_VALIDE => 'Validé',
    ];

    protected $fillable = [
        'uuid', 'epreuve_id', 'candidat_id', 'statut',
        'statut_papier', 'statut_numerique', 'journal_horodate',
        'nature_divergence', 'empreinte_source',
        'detecte_par', 'detecte_at',
        'instructeur_id', 'instruit_at', 'statut_retenu',
        'motif_instruction', 'preuve_reference',
        'valideur_id', 'valide_at', 'commentaire_validation',
    ];

    protected function casts(): array
    {
        return [
            'journal_horodate' => 'datetime',
            'detecte_at' => 'datetime',
            'instruit_at' => 'datetime',
            'valide_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (EvaluationReconciliationEcart $ecart): void {
            if (empty($ecart->uuid)) {
                $ecart->uuid = (string) Str::uuid();
            }

            if (empty($ecart->statut)) {
                $ecart->statut = self::STATUT_OUVERT;
            }

            if (empty($ecart->detecte_at)) {
                $ecart->detecte_at = now();
            }
        });
    }

    public function epreuve(): BelongsTo
    {
        return $this->belongsTo(Epreuve::class, 'epreuve_id');
    }

    public function candidat(): BelongsTo
    {
        return $this->belongsTo(Candidat::class, 'candidat_id');
    }

    public function detecteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'detecte_par');
    }

    public function instructeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructeur_id');
    }

    public function valideur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valideur_id');
    }

    public function estOuvert(): bool
    {
        return $this->statut === self::STATUT_OUVERT;
    }

    public function estInstruit(): bool
    {
        return $this->statut === self::STATUT_INSTUIT;
    }

    public function estValide(): bool
    {
        return $this->statut === self::STATUT_VALIDE;
    }

    public function libelleStatut(): string
    {
        return self::LIBELLES_STATUTS[$this->statut] ?? (string) $this->statut;
    }
}
