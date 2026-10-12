<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SupervisionService;
use Illuminate\Http\JsonResponse;

/**
 * Sonde de readiness (§21.3) : dépendances réelles, sans token pour
 * les équilibreurs de charge, SANS aucune donnée métier ni PII.
 */
class ReadyController extends Controller
{
    public function __invoke(SupervisionService $supervision): JsonResponse
    {
        $sondes = $supervision->sondes();
        $pret = array_all($sondes, fn (array $s) => $s['ok']);

        return response()->json([
            'status' => $pret ? 'ready' : 'not_ready',
            'sondes' => array_map(fn (array $s) => [
                'nom' => $s['nom'],
                'ok' => $s['ok'],
                'detail' => $s['detail'],
            ], $sondes),
        ], $pret ? 200 : 503);
    }
}
