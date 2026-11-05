<?php

namespace App\Modules\Evaluation\Models;

use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historique append-only des valeurs de note d'épreuve (LOOP 05 +
 * industrialisation §2.7/SEC-012) : ancienne valeur, nouvelle valeur,
 * motif, auteur, horodatage.
 *
 * Règle §0.2-8 : un historique critique n'est pas modifiable. Toute
 * correction produit une nouvelle ligne (aucune mise à jour ni
 * suppression applicative — les 5 sites d'écriture créent seulement).
 */
class NoteEpreuveHistorique extends Model
{
    protected $table = 'evaluation_notes_historique';

    public $timestamps = false;

    protected $fillable = [
        'evaluation_note_id', 'ancienne_valeur', 'nouvelle_valeur', 'motif', 'auteur_id', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'ancienne_valeur' => 'decimal:2',
            'nouvelle_valeur' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    /** Append-only : toute modification est refusée au niveau modèle. */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new DomainException('L\'historique des notes est append-only : modification interdite.');
        });

        static::deleting(function () {
            throw new DomainException('L\'historique des notes est append-only : suppression interdite.');
        });
    }

    public function note(): BelongsTo
    {
        return $this->belongsTo(NoteEpreuve::class, 'evaluation_note_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }
}
