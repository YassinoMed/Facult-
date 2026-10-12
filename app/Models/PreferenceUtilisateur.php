<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Préférences serveur (Phase 7 §7.4) : densité, lignes par page.
 * Une ligne par utilisateur — le reste (thème, sidebar) demeure
 * en localStorage (préférence d'affichage locale, pas métier).
 */
class PreferenceUtilisateur extends Model
{
    protected $table = 'user_preferences';

    public const DENSITES = ['compact', 'confortable'];

    protected $fillable = [
        'user_id', 'densite', 'lignes_par_page',
    ];

    protected function casts(): array
    {
        return ['lignes_par_page' => 'integer'];
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
