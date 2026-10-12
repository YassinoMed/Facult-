<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historique des situations d'un étudiant (§10) : append-only, jamais
 * écrasé — reconstruit la situation à une date donnée.
 */
class SituationEtudiant extends Model
{
    protected $table = 'situations_etudiant';

    public const TYPES = [
        'statut', 'groupe', 'programme', 'suspension', 'transfert',
        'reinscription', 'note',
    ];

    protected $fillable = [
        'etudiant_id', 'type', 'ancienne_valeur', 'nouvelle_valeur',
        'motif', 'auteur_id', 'date_effet', 'date_fin',
    ];

    protected function casts(): array
    {
        return ['date_effet' => 'date', 'date_fin' => 'date'];
    }

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }
}
