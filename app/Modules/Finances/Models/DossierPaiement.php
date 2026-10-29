<?php

namespace App\Modules\Finances\Models;

use App\Models\InscriptionAcademique;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * LOOP-UNIREGISTRE-INSCRIPTIONS-PAIEMENTS-01 : dossier de paiement
 * d'UNE inscription annuelle.
 *
 * Le dossier ne porte aucun montant en dur : le montant attendu est
 * recalculé depuis les règles officielles existantes (facture ou tarif)
 * et instantané dans chaque reçu au moment du dépôt.
 *
 * Les statuts sont ceux du parcours de vérification :
 * NON_PAYE → RECU_DEPOSE → EN_VERIFICATION → VALIDE | REFUSE.
 */
class DossierPaiement extends Model
{
    protected $table = 'dossiers_paiement';

    public const NON_PAYE = 'NON_PAYE';

    public const RECU_DEPOSE = 'RECU_DEPOSE';

    public const EN_VERIFICATION = 'EN_VERIFICATION';

    public const VALIDE = 'VALIDE';

    public const REFUSE = 'REFUSE';

    public const STATUTS = [
        self::NON_PAYE,
        self::RECU_DEPOSE,
        self::EN_VERIFICATION,
        self::VALIDE,
        self::REFUSE,
    ];

    /** Statuts nécessitant une action administrative. */
    public const STATUTS_A_TRAITER = [
        self::RECU_DEPOSE,
        self::EN_VERIFICATION,
    ];

    protected $fillable = [
        'inscription_academique_id',
        'statut',
        'pris_en_charge_par',
        'pris_en_charge_le',
        'decide_par',
        'decide_le',
    ];

    protected function casts(): array
    {
        return [
            'pris_en_charge_le' => 'datetime',
            'decide_le' => 'datetime',
        ];
    }

    public function inscription(): BelongsTo
    {
        return $this->belongsTo(InscriptionAcademique::class, 'inscription_academique_id');
    }

    /**
     * Reçus du dossier, du plus récent au plus ancien. Aucun reçu n'est
     * jamais supprimé : un refus conserve la version refusée.
     */
    public function recus(): HasMany
    {
        return $this->hasMany(RecuPaiement::class, 'dossier_paiement_id')
            ->orderByDesc('version');
    }

    public function prisEnChargePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pris_en_charge_par');
    }

    public function decidePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decide_par');
    }

    public function recuCourant(): ?RecuPaiement
    {
        return $this->recus()->first();
    }

    public function estValide(): bool
    {
        return $this->statut === self::VALIDE;
    }

    /**
     * Un nouveau reçu n'est possible que si aucun reçu n'est en attente
     * et si le dossier n'est pas déjà validé.
     */
    public function peutDeposerRecu(): bool
    {
        return in_array($this->statut, [self::NON_PAYE, self::REFUSE], true);
    }

    public function libelleStatut(): string
    {
        return (string) config(
            'inscriptions-paiements.statuts.'.$this->statut,
            $this->statut
        );
    }
}
