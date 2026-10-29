<?php

namespace App\Modules\GouvernanceConformite\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lot 19 — Politique de rétention d'un journal (SAISIE, APPROUVÉE).
 *
 * - `duree_jours` : durée de conservation décisionnée par l'administration —
 *   JAMAIS de valeur par défaut inventée ;
 * - cycle : brouillon → approuvee (approbation tracée) ; UNIQUE par journal ;
 * - le module ne SUPPRIME RIEN : la politique sert de RÉFÉRENCE au rapport
 *   de conformité (lignes au-delà de la durée = signalées, jamais effacées) ;
 * - conformément à la réserve éthique du cahier : aucune promesse
 *   d'immuabilité — le rapport décrit l'état réel des journaux.
 */
class PolitiqueRetention extends Model
{
    protected $table = 'politiques_retention';

    public const STATUTS = ['brouillon', 'approuvee'];

    /** @var array<int, string> */
    protected $fillable = [
        'source_journal', 'duree_jours', 'statut', 'motif',
        'approuve_par', 'approuve_le',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['approuve_le' => 'datetime'];
    }

    public function approuvePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approuve_par');
    }
}
