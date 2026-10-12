<?php

namespace App\Modules\ParcoursAcademique\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Modules\ParcoursAcademique\Models\ParcoursDispense;
use App\Modules\ParcoursAcademique\Models\ParcoursEquivalence;
use App\Modules\ParcoursAcademique\Models\ParcoursPasserelle;
use App\Modules\ParcoursAcademique\Models\ParcoursReorientation;
use App\Modules\ParcoursAcademique\Requests\StoreAmenagementRequest;
use App\Modules\ParcoursAcademique\Services\AmenagementParcoursService;
use App\Modules\ParcoursAcademique\Services\PlanParcoursService;
use App\Modules\ParcoursAcademique\Services\RisqueAcademiqueService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Aménagements : équivalences, dispenses, réorientations,
 * passerelles + plans + risques. Workflows verrouillés,
 * audit systématique, historique préservé.
 */
class AmenagementParcoursController extends Controller
{
    public function index(Request $request, string $categorie): View
    {
        Gate::authorize('parcours.view');
        abort_unless(in_array($categorie, ['equivalences', 'dispenses', 'reorientations', 'passerelles', 'risques'], true), 404);

        $modele = $this->modele($categorie);
        $query = $modele::with('etudiant')->orderByDesc('id');

        if ($faculteId = $request->user()->faculteRestreinteId()) {
            $query->whereHas('etudiant', fn ($q) => $q->where('faculte_id', $faculteId));
        }

        return view('modules.parcours-academique.amenagements-index', [
            'categorie' => $categorie,
            'elements' => $query->paginate(15)->withQueryString(),
        ]);
    }

    public function store(
        StoreAmenagementRequest $request,
        AmenagementParcoursService $service,
        string $categorie,
    ): RedirectResponse {
        abort_unless(in_array($categorie, ['equivalences', 'dispenses', 'reorientations', 'passerelles'], true), 404);

        $donnees = $request->validated();
        $etudiant = Etudiant::findOrFail($donnees['etudiant_id']);

        abort_unless($request->user()->peutAccederFaculte((int) $etudiant->faculte_id), 404);
        Gate::authorize('parcours.gerer');

        match ($categorie) {
            'equivalences' => $service->proposerEquivalence($etudiant, $donnees, $request->user()->id),
            'dispenses' => $service->proposerDispense($etudiant, $donnees, $request->user()->id),
            'reorientations' => $service->proposerReorientation($etudiant, $donnees, $request->user()->id),
            default => $service->proposerPasserelle($etudiant, $donnees, $request->user()->id),
        };

        return back()->with('success', 'Proposition enregistrée.');
    }

    public function decider(Request $request, AmenagementParcoursService $service, string $categorie, int $id): RedirectResponse
    {
        abort_unless(in_array($categorie, ['equivalences', 'dispenses', 'reorientations', 'passerelles'], true), 404);

        $modele = $this->modele($categorie);
        $element = $modele::with('etudiant')->findOrFail($id);

        abort_unless($request->user()->peutAccederFaculte((int) $element->etudiant->faculte_id), 404);
        Gate::authorize('parcours.gerer');

        // Concurrence : deux admins = second 409 via lockForUpdate.
        $valides = $request->validate([
            'statut' => ['required', 'string'],
            'decision' => ['nullable', 'string', 'max:200'],
            'justification' => ['nullable', 'string', 'max:2000'],
        ]);

        match ($categorie) {
            'equivalences' => $service->deciderEquivalence($element, $valides['decision'] ?? 'refusee', $valides['justification'] ?? null, $request->user()->id),
            'dispenses' => $service->deciderDispense($element, $valides['statut'], $valides['decision'] ?? null, $request->user()->id),
            'reorientations' => $service->deciderReorientation($element, $valides['statut'], $valides['decision'] ?? null, $request->user()->id),
            default => $service->deciderPasserelle($element, $valides['statut'], $valides['decision'] ?? null, $request->user()->id),
        };

        return back()->with('success', 'Décision enregistrée et auditée.');
    }

    public function planifier(Request $request, PlanParcoursService $plans): RedirectResponse
    {
        $donnees = $request->validate([
            'etudiant_id' => ['required', 'integer', 'exists:etudiants,id'],
            'objectif' => ['required', 'string', 'max:200'],
            'contenu' => ['required', 'array'],
        ]);

        $etudiant = Etudiant::findOrFail($donnees['etudiant_id']);
        abort_unless($request->user()->peutAccederFaculte((int) $etudiant->faculte_id), 404);
        Gate::authorize('parcours.gerer');

        $plans->proposerPlan($etudiant, null, $donnees['objectif'], $donnees['contenu'], $request->user()->id);

        return back()->with('success', 'Plan proposé.');
    }

    public function risque(Request $request, Etudiant $etudiant, RisqueAcademiqueService $risques): RedirectResponse
    {
        abort_unless($request->user()->peutAccederFaculte((int) $etudiant->faculte_id), 404);
        Gate::authorize('parcours.view');

        $risques->enregistrer($etudiant);

        return back()->with('success', 'Évaluation du risque enregistrée (informative).');
    }

    /** @return class-string */
    private function modele(string $categorie): string
    {
        return match ($categorie) {
            'equivalences' => ParcoursEquivalence::class,
            'dispenses' => ParcoursDispense::class,
            'reorientations' => ParcoursReorientation::class,
            'passerelles' => ParcoursPasserelle::class,
            default => ParcoursEquivalence::class,
        };
    }
}
