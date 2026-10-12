<?php

namespace App\Modules\Referentiel\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Matiere;
use App\Modules\Referentiel\Services\PrerequisService;
use App\Support\Recherche;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ECUE = Matiere rattachée au LMD (§14, §17) : rattachement UE, volumes,
 * évaluation, prérequis (anti-cycle). Le catalogue (code/nom/crédits)
 * reste géré par le cœur — ici, la dimension LMD uniquement.
 */
class EcuesController extends Controller
{
    public function __construct(
        private readonly PrerequisService $prerequis,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('referentiel.view');

        // Audit perf 08/10/2026 : plus d'eager « notes » (jamais affiché
        // dans la vue — chargeait toutes les notes de chaque ECUE).
        $query = Matiere::with('ue')
            ->whereNotNull('ue_id');

        if ($request->user()->faculteRestreinteId() !== null) {
            $faculteId = $request->user()->faculteRestreinteId();
            $query->whereHas('ue.programme', fn ($q) => $q->where('faculte_id', $faculteId));
        }

        if ($search = trim($request->input('search', ''))) {
            $motif = Recherche::contient($search);
            $query->where(fn ($w) => $w->where('code', 'like', $motif)->orWhere('nom', 'like', $motif));
        }

        return view('modules.referentiel.ecues.index', [
            'ecues' => $query->orderBy('ordre')->orderBy('code')->paginate(12)->withQueryString(),
        ]);
    }

    public function show(Request $request, Matiere $ecue): View
    {
        Gate::authorize('referentiel.view');
        abort_unless($ecue->ue_id !== null, 404);
        $this->abortHorsPerimetre($request, $ecue);

        $ecue->loadMissing(['ue.semestre.niveau.programme', 'prerequis', 'requisePar']);

        return view('modules.referentiel.ecues.show', ['ecue' => $ecue]);
    }

    public function edit(Request $request, Matiere $ecue): View
    {
        Gate::authorize('referentiel.manage');
        abort_unless($ecue->ue_id !== null, 404);
        $this->abortHorsPerimetre($request, $ecue);

        return view('modules.referentiel.ecues.form', ['ecue' => $ecue]);
    }

    public function update(Request $request, Matiere $ecue): RedirectResponse
    {
        Gate::authorize('referentiel.manage');
        abort_unless($ecue->ue_id !== null, 404);
        $this->abortHorsPerimetre($request, $ecue);

        $donnees = $request->validate([
            'description' => 'nullable|string|max:2000',
            'volume_cm' => 'nullable|integer|min:0|max:999',
            'volume_td' => 'nullable|integer|min:0|max:999',
            'volume_tp' => 'nullable|integer|min:0|max:999',
            'type_evaluation' => ['nullable', 'string', 'max:30', Rule::exists('evaluation_types_evaluation', 'code')->where('actif', true)],
        ]);

        $ecue->update($donnees);

        return redirect()->route('admin.referentiel.ecues.show', $ecue)
            ->with('success', 'ECUE mise à jour.');
    }

    public function attacherPrerequis(Request $request, Matiere $ecue): RedirectResponse
    {
        Gate::authorize('referentiel.manage');
        abort_unless($ecue->ue_id !== null, 404);
        $this->abortHorsPerimetre($request, $ecue);

        $prerequis = Matiere::findOrFail($request->validate([
            'prerequis_id' => 'required|exists:matieres,id',
        ])['prerequis_id']);

        try {
            $this->prerequis->ajouter($ecue, $prerequis);
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['prerequis' => $e->getMessage()]);
        }

        return redirect()->route('admin.referentiel.ecues.show', $ecue)
            ->with('success', 'Prérequis ajouté.');
    }

    public function detacherPrerequis(Request $request, Matiere $ecue, Matiere $prerequis): RedirectResponse
    {
        Gate::authorize('referentiel.manage');
        $this->abortHorsPerimetre($request, $ecue);

        $this->prerequis->retirer($ecue, $prerequis);

        return redirect()->route('admin.referentiel.ecues.show', $ecue)
            ->with('success', 'Prérequis retiré.');
    }

    /**
     * Périmètre serveur par instance (INV-6, audit 08/10/2026) : une
     * ECUE hors faculté d'un gestionnaire restreint est introuvable
     * (404), comme dans le CRUD générique
     * (ReferentielPolicy::dansPerimetre). Les matières sans UE sont
     * transversales (faculteId null) donc accessibles.
     */
    private function abortHorsPerimetre(Request $request, Matiere $ecue): void
    {
        $faculteId = $ecue->faculteId();

        if ($faculteId !== null && ! $request->user()->peutAccederFaculte($faculteId)) {
            abort(404);
        }
    }
}
