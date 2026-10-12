<?php

namespace App\Modules\Versioning\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Versioning\Models\Deployment;
use App\Modules\Versioning\Models\Release;
use App\Modules\Versioning\Services\CompatibilityService;
use App\Modules\Versioning\Services\DeploymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Contrôleur d'orchestration des déploiements et rollbacks.
 */
class DeploymentController extends Controller
{
    public function __construct(
        private readonly DeploymentService $deploymentService,
        private readonly CompatibilityService $compatibility,
    ) {}

    /**
     * Historique général des opérations de déploiement et rollback.
     */
    public function index(): View
    {
        $deploiements = Deployment::with(['release', 'deployeur'])
            ->orderByDesc('id')
            ->paginate(20);

        return view('modules.versioning.deployments.index', compact('deploiements'));
    }

    /**
     * Détail d'une opération de déploiement.
     */
    public function show(Deployment $deployment): View
    {
        $deployment->load(['release', 'deployeur']);

        return view('modules.versioning.deployments.show', compact('deployment'));
    }

    /**
     * Page de préparation et validation pré-déploiement.
     */
    public function create(Release $release): View
    {
        Gate::authorize('deploy', $release);

        $checklist = $this->compatibility->verifierPreDeploiement($release);

        return view('modules.versioning.deployments.create', compact('release', 'checklist'));
    }

    /**
     * Exécution du déploiement avec double confirmation.
     */
    public function store(Request $request, Release $release): RedirectResponse
    {
        Gate::authorize('deploy', $release);

        $request->validate([
            'confirmation' => ['required', 'string', 'in:DEPLOYER'],
            'sans_backup' => ['nullable', 'boolean'],
            'forcer' => ['nullable', 'boolean'],
        ]);

        try {
            $deploiement = $this->deploymentService->deployer($release, auth()->id(), [
                'sans_backup' => (bool) $request->input('sans_backup', false),
                'forcer' => (bool) $request->input('forcer', false),
            ]);

            return redirect()
                ->route('admin.versioning.deployments.show', $deploiement)
                ->with('succes', "Release {$release->version} déployée avec succès en {$deploiement->duree_secondes}s.");
        } catch (\Throwable $e) {
            return back()
                ->withInput()
                ->with('erreur', 'Échec du déploiement : '.$e->getMessage());
        }
    }

    /**
     * Page de planification et validation avant rollback.
     */
    public function rollbackPlan(Release $release): View
    {
        Gate::authorize('rollback', $release);

        try {
            $plan = $this->deploymentService->planifierRollback($release);

            return view('modules.versioning.deployments.rollback', compact('release', 'plan'));
        } catch (\Throwable $e) {
            return back()->with('erreur', $e->getMessage());
        }
    }

    /**
     * Exécution du rollback vers la release cible.
     */
    public function rollback(Request $request, Release $release): RedirectResponse
    {
        Gate::authorize('rollback', $release);

        $request->validate([
            'confirmation' => ['required', 'string', 'in:ROLLBACK'],
            'rollback_db' => ['nullable', 'boolean'],
        ]);

        try {
            $deploiement = $this->deploymentService->rollback($release, auth()->id(), [
                'rollback_db' => (bool) $request->input('rollback_db', false),
            ]);

            return redirect()
                ->route('admin.versioning.deployments.show', $deploiement)
                ->with('succes', "Rollback vers la release {$release->version} terminé avec succès.");
        } catch (\Throwable $e) {
            return back()
                ->withInput()
                ->with('erreur', 'Échec du rollback : '.$e->getMessage());
        }
    }
}
