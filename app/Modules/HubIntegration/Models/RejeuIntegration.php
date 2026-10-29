<?php

namespace App\Modules\HubIntegration\Models;

use App\Models\IntegrationLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lot 18 — Rejeu autorisé-audité d'un échange en échec.
 *
 * - BORNÉ : le plafond applicatif (ServiceHub::PLAFOND_REJEUX) limite le
 *   nombre de rejeus par log ; au-delà → refus explicite (jamais de boucle) ;
 * - AUTORISÉ-AUDITÉ : chaque rejeu porte un motif, un auteur, une date ;
 * - `integration_logs` reste INSERT-ONLY : ce rejeu est un ORDRE TRACÉ,
 *   l'exécution reste à la charge du connecteur (le payload brut n'est
 *   jamais stocké par conception — seul son SHA-256 existe) ;
 * - cycle : autorise → execute (marqué par l'agent après relance réelle).
 */
class RejeuIntegration extends Model
{
    protected $table = 'rejeux_integration';

    public const STATUTS = ['autorise', 'execute'];

    /** @var array<int, string> */
    protected $fillable = [
        'integration_log_id', 'tentative_numero', 'motif',
        'autorise_par', 'autorise_le', 'statut',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['autorise_le' => 'datetime'];
    }

    public function log(): BelongsTo
    {
        return $this->belongsTo(IntegrationLog::class, 'integration_log_id');
    }

    public function autorisePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autorise_par');
    }
}
