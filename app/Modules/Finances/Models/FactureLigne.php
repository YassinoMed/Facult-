<?php

namespace App\Modules\Finances\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FactureLigne extends Model
{
    protected $table = 'facture_lignes';

    protected $fillable = ['facture_id', 'tarif_id', 'libelle', 'montant_centimes'];

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class, 'facture_id');
    }

    public function tarif(): BelongsTo
    {
        return $this->belongsTo(Tarif::class, 'tarif_id');
    }
}
