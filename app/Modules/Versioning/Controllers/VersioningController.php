<?php

namespace App\Modules\Versioning\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Versioning\Models\AuditVersioning;
use App\Modules\Versioning\Models\Deployment;
use App\Modules\Versioning\Models\Release;
use App\Modules\Versioning\Services\CompatibilityService;
use App\Modules\Versioning\Services\DriftDetectionService;
use App\Modules\Versioning\Services\ManifestService;
use App\Modules\Versioning\Services\VersioningService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Contrôleur principal du dashboard de versionnage.
 */
class VersioningController extends Controller
{
    public function __construct(
        private readonly VersioningService $versioning,
        private readonly CompatibilityService $compatibility,
        private readonly DriftDetectionService $driftService,
        private readonly ManifestService $manifestService,
    ) {}

    /**
     * Dashboard général de versionnage.
     */
    public function index(): View
    {
        $etat = $this->versioning->etatCourant();
        $releaseDeployee = Release::where('statut', Release::STATUT_DEPLOYEE)
            ->latest('deployed_at')
            ->first();

        $releasesRecentes = Release::with(['createur', 'approbateur', 'deployeur'])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        $deploiementsRecents = Deployment::with(['release', 'deployeur'])
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        $compat = $this->compatibility->verifierSysteme();
        $drift = $this->driftService->detecter($releaseDeployee);

        return view('modules.versioning.index', compact(
            'etat',
            'releaseDeployee',
            'releasesRecentes',
            'deploiementsRecents',
            'compat',
            'drift'
        ));
    }

    /**
     * Page de détection de dérive (Drift).
     */
    public function drift(): View
    {
        $releaseDeployee = Release::where('statut', Release::STATUT_DEPLOYEE)
            ->latest('deployed_at')
            ->first();

        $drift = $this->driftService->detecter($releaseDeployee);
        $manifest = $releaseDeployee ? $this->manifestService->charger($releaseDeployee->version) : null;

        return view('modules.versioning.drift', compact('drift', 'releaseDeployee', 'manifest'));
    }

    /**
     * Page de contrôle de compatibilité du système.
     */
    public function compatibilite(): View
    {
        $compat = $this->compatibility->verifierSysteme();
        $releases = Release::orderByDesc('created_at')->limit(10)->get();

        return view('modules.versioning.compatibilite', compact('compat', 'releases'));
    }

    /**
     * Téléchargement ou consultation d'un manifest en JSON.
     */
    public function manifest(string $version)
    {
        $manifest = $this->manifestService->charger($version);

        if ($manifest === null) {
            abort(404, "Manifest pour la version {$version} introuvable.");
        }

        return response()->json($manifest, 200, [
            'Content-Disposition' => "attachment; filename=\"manifest-{$version}.json\"",
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Journal d'audit dédié au versioning.
     */
    public function audit(Request $request): View
    {
        $query = AuditVersioning::with('utilisateur')
            ->orderByDesc('created_at');

        if ($request->filled('action')) {
            $query->where('action', $request->query('action'));
        }

        if ($request->filled('entite')) {
            $query->where('entite', $request->query('entite'));
        }

        $audits = $query->paginate(25)->withQueryString();

        return view('modules.versioning.audit', compact('audits'));
    }
}
