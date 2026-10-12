<?php

namespace App\Modules\OnlineRequests\Services;

use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Services\AuditService;

/**
 * Audit des demandes (§10/§15) : centralisé via AuditService —
 * traçabilité complète (acteur, IP, UA, avant/après), sans secret.
 */
class AuditDemandes
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  array<string, mixed>  $contexte
     */
    public function tracer(OnlineRequest $demande, string $evenement, array $contexte = []): void
    {
        $demande->loadMissing('etudiant');

        $this->audit->tracer(
            $demande->etudiant,
            "online_request.{$evenement}",
            'online_request',
            $demande->id,
            ['statut' => $demande->getOriginal('status')],
            array_merge(['statut' => $demande->status, 'reference' => $demande->reference], $contexte),
        );
    }
}
