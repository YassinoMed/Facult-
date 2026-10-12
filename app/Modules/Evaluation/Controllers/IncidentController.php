<?php

namespace App\Modules\Evaluation\Controllers;

use App\Modules\Evaluation\Events\EvaluationChanged;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\EvaluationIncident;
use App\Modules\Evaluation\Requests\StoreIncidentRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class IncidentController
{
    public function index(Epreuve $epreuve): View
    {
        // Scope faculté → 404 avant toute Gate (convention projet).
        if ($epreuve->faculteId() !== null && request()->user()?->faculteRestreinteId() !== null
            && ! request()->user()->peutAccederFaculte($epreuve->faculteId())) {
            abort(404);
        }

        Gate::authorize('view', $epreuve);

        $epreuve->load(['matiere', 'candidats.etudiant', 'incidents' => fn ($q) => $q->latest('id'), 'incidents.etudiant']);

        return view('modules.evaluation.incidents-index', [
            'epreuve' => $epreuve,
            'incidents' => $epreuve->incidents,
        ]);
    }

    /**
     * Signalement : le verrou de l'épreuve ne bloque jamais le constat
     * post-hoc (il protège les notes, pas l'observation).
     */
    public function store(StoreIncidentRequest $request, Epreuve $epreuve): RedirectResponse
    {
        if ($epreuve->faculteId() !== null && request()->user()?->faculteRestreinteId() !== null
            && ! request()->user()->peutAccederFaculte($epreuve->faculteId())) {
            abort(404);
        }

        Gate::authorize('update', $epreuve);

        $valides = $request->validated();

        if (! empty($valides['etudiant_id'])
            && ! Candidat::where('epreuve_id', $epreuve->id)->where('etudiant_id', $valides['etudiant_id'])->exists()) {
            return back()->withErrors(['etudiant_id' => 'Étudiant non convoqué à cette épreuve.'])->withInput();
        }

        $incident = new EvaluationIncident($request->safe()->only(['type', 'description', 'etudiant_id']));
        $incident->epreuve_id = $epreuve->id;
        $incident->created_by = $request->user()->id;
        $incident->save();

        EvaluationChanged::dispatch('exam.incident.signale', $incident->id);

        return redirect()->route('admin.evaluations.epreuves.incidents.index', $epreuve)
            ->with('succes', 'Incident signalé.');
    }

    public function transiter(EvaluationIncident $incident, string $cible): RedirectResponse
    {
        $incident->loadMissing('epreuve');
        $epreuve = $incident->epreuve;

        if ($epreuve->faculteId() !== null && request()->user()?->faculteRestreinteId() !== null
            && ! request()->user()->peutAccederFaculte($epreuve->faculteId())) {
            abort(404);
        }

        Gate::authorize('update', $epreuve);

        if (! in_array($cible, EvaluationIncident::STATUTS, true) || ! $incident->peutTransiterVers($cible)) {
            return back()->withErrors(['statut' => "Transition interdite depuis « {$incident->statut} »."]);
        }

        DB::transaction(function () use ($incident, $cible): void {
            // Assignation explicite : `statut` reste hors $fillable
            // (jamais mass-assignable depuis une entrée HTTP).
            $ligne = EvaluationIncident::whereKey($incident->id)->lockForUpdate()->firstOrFail();
            $ligne->statut = $cible;
            $ligne->save();
        });

        EvaluationChanged::dispatch('exam.incident.traite', $incident->id);

        return back()->with('succes', "Incident passé à « {$cible} ».");
    }
}
