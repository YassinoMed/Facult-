<?php

namespace App\Modules\GouvernanceConformite\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lot 19 — Journal des accès aux journaux (méta-journal).
 *
 * Réserve éthique du cahier (« éviter la surveillance disproportionnée ») :
 * trace uniquement le FAIT de consulter une vue de gouvernance (vue +
 * filtre générique + utilisateur + date) — JAMAIS le contenu des lignes
 * lues, jamais les résultats, jamais les requêtes.
 */
class JournalAccesJournal extends Model
{
    protected $table = 'journal_acces_journaux';

    /** @var array<int, string> */
    protected $fillable = ['utilisateur_id', 'vue', 'source_journal'];

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'utilisateur_id');
    }
}
