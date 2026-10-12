<?php

namespace App\Modules\Finances\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Remboursement extends Model
{
    protected $table = 'remboursements';

    public const STATUTS = ['requested', 'approved', 'processed', 'completed', 'rejected'];

    public const TRANSITIONS = [
        'requested' => ['approved', 'rejected'],
        'approved' => ['processed', 'rejected'],
        'processed' => ['completed'],
        'completed' => [],
        'rejected' => [],
    ];

    protected $fillable = [
        'paiement_id', 'montant_centimes', 'statut', 'motif',
        'demande_par', 'traite_par', 'traite_a',
    ];

    protected function casts(): array
    {
        return ['traite_a' => 'datetime'];
    }

    public function paiement(): BelongsTo
    {
        return $this->belongsTo(Paiement::class, 'paiement_id');
    }

    public function peutTransiterVers(string $cible): bool
    {
        return in_array($cible, self::TRANSITIONS[$this->statut] ?? [], true);
    }
}
