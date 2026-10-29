<?php

namespace App\Modules\OnlineRequests\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Délégation de l'autorité de validation des demandes (LOT 01) :
 * le délégant — qui détient `demandes.approve` — accorde au
 * délégataire le pouvoir d'approuver/rejeter pendant une fenêtre
 * bornée. Révocable, jamais auto-accordée, et la séparation des
 * fonctions (quatre yeux) s'applique toujours au délégataire.
 */
class RequestDelegation extends Model
{
    protected $table = 'request_delegations';

    protected $fillable = [
        'delegue_par',
        'delegue_a',
        'faculte_id',
        'valide_depuis',
        'valide_jusqua',
        'motif',
        'created_by',
        'revoked_at', // serveur uniquement (révocation par le service)
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valide_depuis' => 'datetime',
            'valide_jusqua' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function deleguant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegue_par');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function delegataire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegue_a');
    }

    public function estActive(): bool
    {
        return $this->revoked_at === null
            && $this->valide_depuis->lessThanOrEqualTo(now())
            && $this->valide_jusqua->greaterThanOrEqualTo(now());
    }
}
