<?php

namespace App\Modules\Evaluation\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lot 10 — lot d'émargement reçu de la file locale (hors ligne).
 *
 * Données minimisées : identifiants techniques, statut et clé
 * d'idempotence. Aucun nom, e-mail, CIN ni justification libre.
 */
class EmargementHorsLigne extends Model
{
    protected $table = 'emargements_hors_ligne';

    protected $fillable = [
        'epreuve_id', 'candidat_id', 'cle_idempotence', 'statut', 'auteur_id', 'recu_a',
    ];

    protected function casts(): array
    {
        return ['recu_a' => 'datetime'];
    }

    public function epreuve(): BelongsTo
    {
        return $this->belongsTo(Epreuve::class, 'epreuve_id');
    }

    public function candidat(): BelongsTo
    {
        return $this->belongsTo(Candidat::class, 'candidat_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }
}
