<?php

namespace App\Modules\ParcoursAcademique\Controllers;

use App\Contracts\SimulationParcoursInterface;
use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Modules\ParcoursAcademique\Models\ParcoursSimulation;
use App\Modules\ParcoursAcademique\Requests\StoreSimulationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Simulations : contrôleur fin, moteur dans le service.
 * Aucune modification du dossier officiel, persistance
 * append-only explicable.
 */
class SimulationParcoursController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('parcours.view');

        $query = ParcoursSimulation::with(['etudiant', 'inscriptionSource'])
            ->orderByDesc('id');

        if ($faculteId = $request->user()->faculteRestreinteId()) {
            $query->whereHas('etudiant', fn ($q) => $q->where('faculte_id', $faculteId));
        }

        return view('modules.parcours-academique.simulations-index', [
            'simulations' => $query->paginate(15)->withQueryString(),
        ]);
    }

    public function store(StoreSimulationRequest $request, SimulationParcoursInterface $simulations): RedirectResponse
    {
        $donnees = $request->validated();

        /** @var Etudiant $etudiant */
        $etudiant = Etudiant::findOrFail($donnees['etudiant_id']);

        abort_unless($request->user()->peutAccederFaculte((int) $etudiant->faculte_id), 404);
        Gate::authorize('parcours.simuler');

        $source = isset($donnees['inscription_source_id'])
            ? InscriptionAcademique::findOrFail($donnees['inscription_source_id'])
            : null;

        if ($source !== null) {
            abort_unless((int) $source->etudiant_id === (int) $etudiant->id, 404);
        }

        $simulation = $simulations->simuler(
            $etudiant, $donnees['scenario'], $donnees['hypotheses'] ?? [], $source, $request->user()->id
        );

        return redirect()->route('admin.parcours.simulations.show', $simulation)
            ->with('success', 'Simulation enregistrée (sans effet officiel).');
    }

    public function show(Request $request, ParcoursSimulation $simulation): View
    {
        $simulation->load('etudiant');

        abort_unless($request->user()->peutAccederFaculte((int) $simulation->etudiant->faculte_id), 404);
        Gate::authorize('parcours.view');

        return view('modules.parcours-academique.simulation-show', ['simulation' => $simulation]);
    }
}
