<?php

namespace App\Modules\Finances\Models;

use App\Models\AnneeUniversitaire;
use App\Modules\Referentiel\Models\Programme;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Tarif extends Model
{
    protected $table = 'tarifs';

    public const TYPES = ['inscription', 'reinscription', 'dossier', 'certification', 'document', 'autre'];

    protected $fillable = [
        'code', 'libelle', 'montant_centimes', 'annee_universitaire_id',
        'programme_id', 'niveau', 'type_frais', 'actif',
    ];

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    public function anneeUniversitaire(): BelongsTo
    {
        return $this->belongsTo(AnneeUniversitaire::class);
    }

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    public function montantFormate(): string
    {
        return number_format($this->montant_centimes / 100, 2, ',', ' ').' DH';
    }
}
