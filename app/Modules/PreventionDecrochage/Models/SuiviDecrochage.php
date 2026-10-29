<?php

namespace App\Modules\PreventionDecrochage\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lot 15 — Mesure d'accompagnement d'une alerte.
 *
 * Entrée d'historique HUMAIN (mesure, auteur, date), DISTINCTE des
 * résultats officiels : aucune FK vers notes, décisions ou historique
 * académique ; ce dossier appartient au module d'accompagnement seule.
 */
class SuiviDecrochage extends Model
{
    protected $table = 'suivis_decrochage';

    /** @var array<int, string> */
    protected $fillable = ['alerte_id', 'mesure', 'auteur_id'];

    public function alerte(): BelongsTo
    {
        return $this->belongsTo(AlerteDecrochage::class, 'alerte_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }
}
