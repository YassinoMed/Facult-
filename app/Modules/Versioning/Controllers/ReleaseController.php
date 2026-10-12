<?php

namespace App\Modules\Versioning\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Versioning\Models\Release;
use App\Modules\Versioning\Services\ManifestService;
use App\Modules\Versioning\Services\ReleaseService;
use App\Modules\Versioning\Services\VersioningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Gestion des releases : cycle de vie, création, transitions, comparaisons.
 */
class ReleaseController extends Controller
{
    public function __construct(
        private readonly ReleaseService $releaseService,
        private readonly VersioningService $versioning,
        private readonly ManifestService $manifestService,
    ) {}

    /**
     * Liste des releases avec filtres.
     */
    public function index(Request $request): View
    {
        $filtres = [
            'statut' => $request->query('statut'),
            'environnement' => $request->query('environnement'),
            'version' => $request->query('recherche'),
        ];

        $releases = $this->releaseService->historique($filtres, 15);
        $statuts = Release::TOUS_STATUTS;

        return view('modules.versioning.releases.index', compact('releases', 'filtres', 'statuts'));
    }

    /**
     * Formulaire de création d'une nouvelle release.
     */
    public function create(): View
    {
        $appActuelle = $this->versioning->versionApp();
        $dbRelease = $this->versioning->dbRelease();
        $dbActuelle = $dbRelease;
        $dbSchema = $this->versioning->dbSchema();
        $dbSchemaTexte = $dbSchema['texte'];
        $buildCourant = $this->versioning->buildCourant();

        $suggestions = [
            'patch' => $this->versioning->incrementer($appActuelle, 'patch'),
            'minor' => $this->versioning->incrementer($appActuelle, 'minor'),
            'major' => $this->versioning->incrementer($appActuelle, 'major'),
        ];

        $derniereRelease = Release::latest('id')->first();

        return view('modules.versioning.releases.create', compact(
            'appActuelle',
            'dbRelease',
            'dbActuelle',
            'dbSchemaTexte',
            'buildCourant',
            'suggestions',
            'derniereRelease'
        ));
    }

    /**
     * Enregistrement d'une nouvelle release en brouillon.
     */
    public function store(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'version' => ['required', 'string', 'max:50', 'regex:/^\d+\.\d+\.\d+(-[a-zA-Z0-9.]+)?$/'],
            'environnement' => ['required', 'string', 'in:local,recette,production'],
            'app_version' => ['required', 'string', 'max:50'],
            'db_version' => ['required', 'string', 'max:50'],
            'dev_version' => ['nullable', 'string', 'max:50'],
            'prod_version' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'changements' => ['nullable', 'array'],
        ]);

        try {
            $release = $this->releaseService->creer($donnees, auth()->id());

            return redirect()
                ->route('admin.versioning.releases.show', $release)
                ->with('succes', "Release {$release->version} créée avec succès en état brouillon.");
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('erreur', $e->getMessage());
        }
    }

    /**
     * Fiche détaillée d'une release.
     */
    public function show(Release $release): View
    {
        $release->load(['createur', 'approbateur', 'deployeur', 'releasePrecedente', 'deploiements']);
        $manifest = $this->manifestService->charger($release->version);

        // Autres releases pour le menu de comparaison rapide
        $autresReleases = Release::where('id', '!=', $release->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return view('modules.versioning.releases.show', compact('release', 'manifest', 'autresReleases'));
    }

    /**
     * Transition contrôlée du statut d'une release.
     */
    public function transition(Request $request, Release $release): RedirectResponse
    {
        $request->validate([
            'statut' => ['required', 'string', 'in:'.implode(',', Release::TOUS_STATUTS)],
        ]);

        $nouveauStatut = $request->input('statut');

        // Vérifier permission selon la transition demandée
        if ($nouveauStatut === Release::STATUT_APPROUVEE) {
            Gate::authorize('approve', $release);
        } else {
            Gate::authorize('update', $release);
        }

        try {
            $this->releaseService->transiter($release, $nouveauStatut, auth()->id());

            return back()->with('succes', "La release {$release->version} est maintenant en statut '{$release->fresh()->statut_libelle}'.");
        } catch (\Throwable $e) {
            return back()->with('erreur', "Impossible d'effectuer la transition : ".$e->getMessage());
        }
    }

    /**
     * Comparaison détaillée entre deux releases.
     */
    public function comparer(Request $request): View
    {
        $releases = Release::orderByDesc('created_at')->get();

        $sourceId = $request->query('source_id');
        $cibleId = $request->query('cible_id');

        $source = $sourceId ? Release::find($sourceId) : null;
        $cible = $cibleId ? Release::find($cibleId) : null;

        // Valeurs par défaut si non spécifiées : les deux dernières
        if (! $source && ! $cible && $releases->count() >= 2) {
            $source = $releases->get(1);
            $cible = $releases->get(0);
        }

        $resultat = null;
        if ($source && $cible) {
            $resultat = $this->releaseService->comparer($source, $cible);
        }

        return view('modules.versioning.releases.comparer', compact('releases', 'source', 'cible', 'resultat'));
    }
}
