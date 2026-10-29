<?php

namespace App\Modules\PreventionDecrochage\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lot 15 — Règle du catalogue de détection du décrochage.
 *
 * Une règle est EXPLICABLE et APPROUVÉE avant tout usage :
 * - créée en `brouillon` (jamais active sans décision humaine) ;
 * - `raison_explicable` OBLIGATOIRE avant approbation (retenue de
 *   validation, voir ServiceAlertes) ;
 * - version incrémentée à chaque modification, jamais réécrite
 *   rétroactivement sans trace.
 *
 * Le catalogue NE DÉFINIT PAS les seuils institutionnels par défault :
 * chaque règle porte la justification écrite de l'établissement.
 */
class RegleDecrochage extends Model
{
    protected $table = 'regles_decrochage';

    public const STATUTS = ['brouillon', 'approuvee', 'desactivee'];

    /** Types de règles : uniquement des sources confirmées du dépôt. */
    public const TYPES = ['presences', 'notes'];

    /**
     * Mesures disponibles par type (conventions sources confirmées).
     *
     * `seuil` = seuil de DÉCLENCHEMENT de la valeur de la mesure ;
     * `seuil_mesure` = seuil interne de la mesure (notes : une note
     * < seuil_mesure est comptée) — obligatoire pour le type `notes`.
     */
    public const MESURES = [
        'presences' => ['absents', 'retards'],
        'notes' => ['notes_sous_seuil'],
    ];

    /** @var array<int, string> */
    protected $fillable = [
        'code', 'nom', 'type', 'mesure', 'seuil', 'seuil_mesure', 'version', 'statut',
        'raison_explicable', 'approuve_par', 'approuve_le',
        'desactivee_par', 'cree_par',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seuil' => 'decimal:2',
            'seuil_mesure' => 'decimal:2',
            'approuve_le' => 'datetime',
        ];
    }

    public function approuveePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approuve_par');
    }
}
