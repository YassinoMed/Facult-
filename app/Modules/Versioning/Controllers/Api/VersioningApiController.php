<?php

namespace App\Modules\Versioning\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Versioning\Models\Release;
use App\Modules\Versioning\Services\CompatibilityService;
use App\Modules\Versioning\Services\DriftDetectionService;
use App\Modules\Versioning\Services\ManifestService;
use App\Modules\Versioning\Services\VersioningService;
use Illuminate\Http\JsonResponse;

/**
 * API interne de versionnage (§49).
 * Endpoints sécurisés, sans aucun secret ni information sensible.
 */
class VersioningApiController extends Controller
{
    public function __construct(
        private readonly VersioningService $versioning,
        private readonly CompatibilityService $compatibility,
        private readonly DriftDetectionService $driftService,
        private readonly ManifestService $manifestService,
    ) {}

    /**
     * Version actuelle simplifiée (sonde de santé/monitoring).
     */
    public function version(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'app_version' => $this->versioning->versionApp(),
            'db_version' => $this->versioning->versionDb(),
            'build' => $this->versioning->buildCourant(),
            'environnement' => $this->versioning->environnement(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * État complet du système et des composants.
     */
    public function status(): JsonResponse
    {
        $release = Release::where('statut', Release::STATUT_DEPLOYEE)
            ->latest('deployed_at')
            ->first();

        return response()->json([
            'etat' => $this->versioning->etatCourant(),
            'release_active' => $release ? [
                'version' => $release->version,
                'build' => $release->build_number,
                'statut' => $release->statut,
                'deployed_at' => $release->deployed_at?->toIso8601String(),
            ] : null,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Manifest actif.
     */
    public function manifest(): JsonResponse
    {
        $release = Release::where('statut', Release::STATUT_DEPLOYEE)
            ->latest('deployed_at')
            ->first();

        $version = $release?->version ?? $this->versioning->versionApp();
        $manifest = $this->manifestService->charger($version);

        if (! $manifest) {
            return response()->json(['erreur' => 'Manifest non trouvé pour la release active'], 404);
        }

        return response()->json($manifest);
    }

    /**
     * Contrôle de compatibilité.
     */
    public function compatibility(): JsonResponse
    {
        return response()->json($this->compatibility->verifierSysteme());
    }

    /**
     * Rapport de dérive.
     */
    public function drift(): JsonResponse
    {
        return response()->json($this->driftService->detecter());
    }
}
