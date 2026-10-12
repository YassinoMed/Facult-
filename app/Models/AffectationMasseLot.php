<?php

namespace App\Models;

use App\Modules\Schedule\Models\Group;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LOOP P2 — ordre de travail d'affectation de masse : sélections
 * figées + compteurs + erreurs. Statuts : en_attente → en_cours →
 * termine | echoue, annule à tout moment (le Job s'arrête).
 */
class AffectationMasseLot extends Model
{
    public const STATUT_EN_ATTENTE = 'en_attente';

    public const STATUT_EN_COURS = 'en_cours';

    public const STATUT_TERMINE = 'termine';

    public const STATUT_ECHOUE = 'echoue';

    public const STATUT_ANNULE = 'annule';

    protected $table = 'affectation_masse_lots';

    protected $fillable = [
        'reference', 'annee_universitaire_id', 'auteur_id', 'statut',
        'selections', 'motif', 'force', 'total', 'traites', 'affectes',
        'ignores', 'traites_ids', 'erreurs', 'message',
    ];

    protected function casts(): array
    {
        return [
            'selections' => 'array',
            'traites_ids' => 'array',
            'erreurs' => 'array',
            'force' => 'boolean',
        ];
    }

    public function anneeUniversitaire(): BelongsTo
    {
        return $this->belongsTo(AnneeUniversitaire::class, 'annee_universitaire_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }

    public function estModifiable(): bool
    {
        return in_array($this->statut, [self::STATUT_EN_ATTENTE, self::STATUT_EN_COURS], true);
    }

    public function pourcentage(): float
    {
        if ($this->total <= 0) {
            return 0.0;
        }

        return round(min(100, $this->traites / $this->total * 100), 1);
    }

    /**
     * Synthèse §9 (processed/total/percentage/success/blocked/failed),
     * sans PII (compteurs et motifs techniques uniquement).
     *
     * @return array{processed: int, total: int, percentage: float, success: int, blocked: int, failed: int, statut: string}
     */
    public function progression(): array
    {
        return [
            'processed' => $this->traites,
            'total' => $this->total,
            'percentage' => $this->pourcentage(),
            'success' => $this->affectes,
            'blocked' => $this->ignores,
            'failed' => $this->statut === self::STATUT_ECHOUE ? 1 : 0,
            'statut' => $this->statut,
        ];
    }

    /**
     * Groupes concernés (libellés pour l'affichage, sans requête N+1
     * par sélection : un seul whereIn).
     *
     * @return array<int, string>
     */
    public function codesGroupes(): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(fn ($s) => (int) ($s['groupe_id'] ?? 0), $this->selections ?? [])
        )));

        if ($ids === []) {
            return [];
        }

        return Group::whereIn('id', $ids)->pluck('code', 'id')->all();
    }
}
