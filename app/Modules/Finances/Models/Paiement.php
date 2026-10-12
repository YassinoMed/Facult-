<?php

namespace App\Modules\Finances\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Paiement extends Model
{
    protected $table = 'paiements';

    public const METHODES = ['especes', 'virement', 'carte', 'en_ligne', 'autre'];

    public const STATUTS = ['pending', 'confirmed', 'failed', 'cancelled'];

    protected $fillable = [
        'reference', 'facture_id', 'montant_centimes', 'methode', 'statut',
        'reference_externe', 'enregistre_par', 'confirme_par', 'confirme_a',
    ];

    protected function casts(): array
    {
        return ['confirme_a' => 'datetime'];
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class, 'facture_id');
    }

    public function enregistrePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enregistre_par');
    }

    public function remboursements(): HasMany
    {
        return $this->hasMany(Remboursement::class, 'paiement_id');
    }

    public function estConfirme(): bool
    {
        return $this->statut === 'confirmed';
    }
}
