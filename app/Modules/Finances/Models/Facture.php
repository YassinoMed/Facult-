<?php

namespace App\Modules\Finances\Models;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Facture extends Model
{
    protected $table = 'factures';

    public const STATUTS = ['draft', 'issued', 'partially_paid', 'paid', 'overdue', 'cancelled', 'refunded'];

    public const TRANSITIONS = [
        'draft' => ['issued', 'cancelled'],
        'issued' => ['partially_paid', 'paid', 'overdue', 'cancelled'],
        'partially_paid' => ['paid', 'overdue', 'cancelled'],
        'paid' => ['refunded'],
        'overdue' => ['partially_paid', 'paid', 'cancelled'],
        'cancelled' => [],
        'refunded' => [],
    ];

    protected $fillable = [
        'reference', 'etudiant_id', 'annee_universitaire_id', 'inscription_academique_id',
        'statut', 'total_centimes', 'remise_centimes', 'motif_remise', 'date_echeance',
        'created_by', 'annulee_par', 'motif_annulation',
    ];

    protected function casts(): array
    {
        return ['date_echeance' => 'date'];
    }

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class);
    }

    public function anneeUniversitaire(): BelongsTo
    {
        return $this->belongsTo(AnneeUniversitaire::class);
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(FactureLigne::class, 'facture_id');
    }

    public function paiements(): HasMany
    {
        return $this->hasMany(Paiement::class, 'facture_id');
    }

    public function peutTransiterVers(string $cible): bool
    {
        return in_array($cible, self::TRANSITIONS[$this->statut] ?? [], true);
    }

    /** Net à payer après remise, en centimes. */
    public function netCentimes(): int
    {
        return max(0, (int) $this->total_centimes - (int) $this->remise_centimes);
    }

    /** Confirmés uniquement — jamais de reçu avant confirmation réelle. */
    public function payeCentimes(): int
    {
        return (int) $this->paiements()->where('statut', 'confirmed')->sum('montant_centimes');
    }

    public function resteCentimes(): int
    {
        return max(0, $this->netCentimes() - $this->payeCentimes());
    }

    public static function formater(int $centimes): string
    {
        return number_format($centimes / 100, 2, ',', ' ').' DH';
    }
}
