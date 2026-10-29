<?php

namespace App\Modules\Versioning\Services;

use App\Modules\Versioning\Models\AuditVersioning;
use Illuminate\Http\Request;

/**
 * Audit dédié au versioning — append-only, jamais de secrets.
 *
 * Distinct d'AuditService (lié à Etudiant). Même philosophie :
 * écriture seule, acteur + action + entité + diff + IP/UA.
 */
class AuditVersioningService
{
    public function tracer(
        string $action,
        string $entite,
        ?int $entiteId = null,
        ?array $ancienne = null,
        ?array $nouvelle = null,
        ?string $environnement = null,
        ?int $utilisateurId = null,
    ): AuditVersioning {
        /** @var Request|null $request */
        $request = request();
        $utilisateur = auth()->user();

        return AuditVersioning::create([
            'utilisateur_id' => $utilisateurId ?? $utilisateur?->id,
            'action' => $action,
            'entite' => $entite,
            'entite_id' => $entiteId,
            'ancienne_valeur' => $ancienne,
            'nouvelle_valeur' => $nouvelle,
            'environnement' => $environnement ?? config('versioning.environnement'),
            'adresse_ip' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 500) : null,
            'created_at' => now(),
        ]);
    }
}
