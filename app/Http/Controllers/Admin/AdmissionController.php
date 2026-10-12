<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Services\AdmissionService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Admissions (roadmap « admission Master ») : actes d'admission
 * externes, traçés, consommés par la progression LMD.
 * Permission + périmètre faculté (étudiant ET programme cible, 404).
 */
class AdmissionController extends Controller
{
    public function __construct(
        private readonly AdmissionService $admissions,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Admission::class);

        return view('admin.admissions.index', [
            'admissions' => $this->admissions->lister($request->user()->faculteRestreinteId()),
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', Admission::class);

        $restreinte = $request->user()->faculteRestreinteId();

        return view('admin.admissions.creer', [
            'etudiants' => Etudiant::select('id', 'nom', 'prenom', 'cin', 'faculte_id')
                ->when($restreinte !== null, fn ($q) => $q->where('faculte_id', $restreinte))
                ->orderBy('nom')->orderBy('prenom')->limit(500)->get(),
            'programmes' => Programme::when($restreinte !== null, fn ($q) => $q->where('faculte_id', $restreinte))
                ->orderBy('code')->get(),
            'niveaux' => Niveau::with('programme:id,code')
                ->when($restreinte !== null, fn ($q) => $q->whereHas('programme', fn ($p) => $p->where('faculte_id', $restreinte)))
                ->orderBy('code')->get(),
            'annees' => AnneeUniversitaire::orderByDesc('libelle')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $valide = $request->validate([
            'etudiant_id' => ['required', 'integer', 'exists:etudiants,id'],
            'programme_id' => ['required', 'integer', 'exists:programmes,id'],
            'niveau_id' => ['nullable', 'integer', 'exists:niveaux,id'],
            'annee_universitaire_id' => ['required', 'integer', 'exists:annees_universitaires,id'],
            'decision' => ['required', 'in:'.implode(',', Admission::DECISIONS)],
            'motif' => ['nullable', 'string', 'max:500'],
        ]);

        $etudiant = Etudiant::findOrFail($valide['etudiant_id']);
        $programme = Programme::findOrFail($valide['programme_id']);

        // Isolation faculté (scope → 404) : l'étudiant ET la cible.
        abort_unless($request->user()->peutAccederFaculte((int) $etudiant->faculte_id), 404);
        abort_unless($programme->faculte_id === null
            || $request->user()->peutAccederFaculte((int) $programme->faculte_id), 404);

        try {
            $this->admissions->accorder(
                $etudiant,
                $programme,
                isset($valide['niveau_id']) ? Niveau::findOrFail($valide['niveau_id']) : null,
                AnneeUniversitaire::findOrFail($valide['annee_universitaire_id']),
                $valide['decision'],
                $valide['motif'] ?? null,
                (int) $request->user()->id,
            );
        } catch (DomainException $e) {
            return back()->withErrors(['admission' => $e->getMessage()])->withInput();
        }

        return redirect()->route('admin.admissions.index')
            ->with('success', 'Admission enregistrée.');
    }

    public function destroy(Request $request, Admission $admission): RedirectResponse
    {
        $admission->loadMissing(['etudiant', 'programme']);

        // Isolation faculté (scope → 404) — l'étudiant ET la cible.
        abort_unless($request->user()->peutAccederFaculte((int) $admission->etudiant->faculte_id), 404);
        abort_unless($admission->programme->faculte_id === null
            || $request->user()->peutAccederFaculte((int) $admission->programme->faculte_id), 404);

        Gate::authorize('delete', $admission);

        try {
            $this->admissions->supprimer($admission);
        } catch (DomainException $e) {
            return back()->withErrors(['admission' => $e->getMessage()]);
        }

        return redirect()->route('admin.admissions.index')
            ->with('success', 'Admission supprimée.');
    }
}
