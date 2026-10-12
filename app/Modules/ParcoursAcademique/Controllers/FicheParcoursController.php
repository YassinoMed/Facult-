<?php

namespace App\Modules\ParcoursAcademique\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Modules\ParcoursAcademique\Models\ParcoursDispense;
use App\Modules\ParcoursAcademique\Models\ParcoursEquivalence;
use App\Modules\ParcoursAcademique\Models\ParcoursPasserelle;
use App\Modules\ParcoursAcademique\Models\ParcoursPlan;
use App\Modules\ParcoursAcademique\Models\ParcoursReorientation;
use App\Modules\ParcoursAcademique\Models\ParcoursSimulation;
use App\Modules\ParcoursAcademique\Services\ComparaisonParcoursService;
use App\Modules\ParcoursAcademique\Services\CreditParcoursService;
use App\Modules\ParcoursAcademique\Services\DetteAcademiqueService;
use App\Modules\ParcoursAcademique\Services\HistoriqueParcoursService;
use App\Modules\ParcoursAcademique\Services\PlanParcoursService;
use App\Modules\ParcoursAcademique\Services\RisqueAcademiqueService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Fiche académique étudiant : synthèse, crédits, dettes,
 * risques, historique, plans, simulations, aménagements.
 * Isolation faculté + permissions sur chaque accès.
 */
class FicheParcoursController extends Controller
{
    public function show(
        Request $request,
        Etudiant $etudiant,
        PlanParcoursService $plans,
        CreditParcoursService $credits,
        DetteAcademiqueService $dettes,
        RisqueAcademiqueService $risques,
        HistoriqueParcoursService $historique,
    ): View {
        abort_unless($request->user()->peutAccederFaculte((int) $etudiant->faculte_id), 404);
        Gate::authorize('parcours.view');

        $analyse = $plans->analyser($etudiant);
        $source = $analyse['inscription_source_id'] !== null
            ? InscriptionAcademique::find($analyse['inscription_source_id'])
            : null;

        return view('modules.parcours-academique.fiche', [
            'etudiant' => $etudiant,
            'analyse' => $analyse,
            'credits' => $credits->creditsEtudiant($etudiant),
            'dettes' => $dettes->lister($etudiant),
            'risque' => $risques->evaluer($etudiant, $source),
            'chronologie' => $historique->chronologie($etudiant),
            'plans' => ParcoursPlan::where('etudiant_id', $etudiant->id)->orderByDesc('id')->limit(10)->get(),
            'simulations' => ParcoursSimulation::where('etudiant_id', $etudiant->id)->orderByDesc('id')->limit(10)->get(),
            'equivalences' => ParcoursEquivalence::where('etudiant_id', $etudiant->id)->orderByDesc('id')->limit(50)->get(),
            'dispenses' => ParcoursDispense::where('etudiant_id', $etudiant->id)->orderByDesc('id')->limit(50)->get(),
            'reorientations' => ParcoursReorientation::where('etudiant_id', $etudiant->id)->orderByDesc('id')->limit(50)->get(),
            'passerelles' => ParcoursPasserelle::where('etudiant_id', $etudiant->id)->orderByDesc('id')->limit(50)->get(),
        ]);
    }

    public function comparer(Request $request, ComparaisonParcoursService $comparaisons): View
    {
        Gate::authorize('parcours.comparer');

        $faculteId = $request->user()->faculteRestreinteId();

        if (! $request->has('etudiants')) {
            return view('modules.parcours-academique.comparaison', [
                'lignes' => null,
                'candidats' => Etudiant::select(['id', 'nom', 'prenom'])
                    ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
                    ->orderBy('nom')->orderBy('prenom')->limit(500)->get(),
            ]);
        }

        $request->merge(['etudiants' => array_values(array_filter(
            (array) $request->input('etudiants', []),
            fn ($v) => $v !== null && $v !== ''
        ))]);

        $valides = $request->validate([
            'etudiants' => ['required', 'array', 'min:2', 'max:4'],
            'etudiants.*' => ['integer', 'exists:etudiants,id'],
        ]);

        if ($faculteId !== null) {
            foreach (Etudiant::whereIn('id', $valides['etudiants'])->get() as $etudiant) {
                abort_unless((int) $etudiant->faculte_id === (int) $faculteId, 404);
            }
        }

        return view('modules.parcours-academique.comparaison', [
            'lignes' => $comparaisons->comparerEtudiants($valides['etudiants']),
            'candidats' => [],
        ]);
    }
}
