<?php

namespace App\Modules\HubIntegration\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Lot 18 — Audit d'idempotence d'un connecteur (verdict SAISI).
 *
 * Le cahier exige un audit « connecteur par connecteur » : le verdict est
 * une DÉCISION HUMAINE documentée (verifie | doute | absent) avec sa preuve
 * datée — le système ne JAMAIS affirmer l'idempotence globalement ni la
 * déduire du code : un connecteur sans audit est rapporté « non audité »,
 * jamais conforme par défaut (donnée absente ≠ conformité).
 */
class AuditIdempotence extends Model
{
    protected $table = 'audits_idempotence';

    public const VERDICTS = ['verifie', 'doute', 'absent'];

    /** @var array<int, string> */
    protected $fillable = ['connecteur', 'verdict', 'preuve', 'audite_par', 'audite_le'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['audite_le' => 'datetime'];
    }

    public function auditePar()
    {
        return $this->belongsTo(User::class, 'audite_par');
    }
}
