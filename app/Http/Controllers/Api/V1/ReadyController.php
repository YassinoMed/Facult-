<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SupervisionService;
use App\Support\SupervisionAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sonde de readiness (§21.3) : dépendances réelles, sans token pour
 * les équilibreurs de charge, SANS aucune donnée métier ni PII.
 *
 * LOOP-SECURITY-REMEDIATION-01 : réponse PUBLIQUE réduite au seul état ;
 * le détail des sondes (nom de base, pilote de cache, chemin de stockage,
 * occupation disque…) n'est renvoyé qu'aux IP de supervision déclarées
 * dans `SUPERVISION_ALLOWED_IPS`. L'état HTTP (200/503) reste inchangé
 * pour les sondes des load-balancers.
 */
class ReadyController extends Controller
{
    public function __invoke(Request $request, SupervisionService $supervision): JsonResponse
    {
        $sondes = $supervision->sondes();
        $pret = array_all($sondes, fn (array $s) => $s['ok']);

        $charge = [
            'status' => $pret ? 'ready' : 'not_ready',
            'timestamp' => now()->toIso8601String(),
        ];

        if (! SupervisionAccess::autorisee($request->ip())) {
            return response()->json($charge, $pret ? 200 : 503);
        }

        $charge['sondes'] = array_map(fn (array $s) => [
            'nom' => $s['nom'],
            'ok' => $s['ok'],
            'detail' => $s['detail'],
        ], $sondes);

        return response()->json($charge, $pret ? 200 : 503);
    }
}
