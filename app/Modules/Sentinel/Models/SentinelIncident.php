<?php

namespace App\Modules\Sentinel\Models;

use App\Models\Faculte;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Platform Sentinel — incident dédupliqué (`cle_unique`) au cycle de
 * vie serveur. Diagnostic et recommandation sans PII ni trace de pile.
 */
class SentinelIncident extends Model
{
    protected $table = 'sentinel_incidents';

    public const STATUT_OUVERT = 'ouvert';

    public const STATUT_ACQUITTE = 'acquitte';

    public const STATUT_RESOLU = 'resolu';

    public const STATUT_IGNORE = 'ignore';

    public const STATUTS = [
        self::STATUT_OUVERT,
        self::STATUT_ACQUITTE,
        self::STATUT_RESOLU,
        self::STATUT_IGNORE,
    ];

    public const GRAVITES = ['info', 'avertissement', 'erreur', 'critique'];

    /** Transitions serveurs autorisées (aucune réouverture silencieuse). */
    public const TRANSITIONS = [
        self::STATUT_OUVERT => [self::STATUT_ACQUITTE, self::STATUT_RESOLU, self::STATUT_IGNORE],
        self::STATUT_ACQUITTE => [self::STATUT_RESOLU, self::STATUT_OUVERT],
        self::STATUT_RESOLU => [self::STATUT_OUVERT],
        self::STATUT_IGNORE => [self::STATUT_OUVERT],
    ];

    protected $fillable = [
        'cle_unique', 'code', 'categorie', 'gravite', 'module',
        'titre', 'diagnostic', 'recommandation', 'statut',
        'entite_type', 'entite_id', 'entite_libelle', 'faculte_id',
        'detecte_le', 'derniere_detection_le', 'derniere_notification_le',
        'resolu_le', 'compteur',
    ];

    protected function casts(): array
    {
        return [
            'detecte_le' => 'datetime',
            'derniere_detection_le' => 'datetime',
            'derniere_notification_le' => 'datetime',
            'resolu_le' => 'datetime',
        ];
    }

    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class, 'faculte_id');
    }

    public function peutTransiterVers(string $cible): bool
    {
        return in_array($cible, self::TRANSITIONS[$this->statut] ?? [], true);
    }

    public function estActif(): bool
    {
        return in_array($this->statut, [self::STATUT_OUVERT, self::STATUT_ACQUITTE], true);
    }

    public function scopeActifs($query)
    {
        return $query->whereIn('statut', [self::STATUT_OUVERT, self::STATUT_ACQUITTE]);
    }
}
