<?php

namespace App\Modules\ParcoursAcademique\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Modules\ParcoursAcademique\Models\ParcoursAlerteRisque;
use App\Modules\ParcoursAcademique\Models\ParcoursDispense;
use App\Modules\ParcoursAcademique\Models\ParcoursEquivalence;
use App\Modules\ParcoursAcademique\Models\ParcoursPasserelle;
use App\Modules\ParcoursAcademique\Models\ParcoursReorientation;
use App\Modules\ParcoursAcademique\Models\ParcoursSimulation;
use App\Modules\ParcoursAcademique\Services\ProspectiveParcoursService;
use App\Modules\Referentiel\Models\Programme;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Tableau de bord académique : risques, simulations récentes.
 * Données scopées faculté, aucune requête lourde en Blade.
 */
class TableauBordParcoursController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('parcours.view');

        $faculteId = $request->user()->faculteRestreinteId();

        $alertes = ParcoursAlerteRisque::with('etudiant')
            ->whereIn('niveau', ['eleve', 'critique'])
            ->where('statut', 'nouvelle')
            ->when($faculteId !== null, fn ($q) => $q->whereHas('etudiant', fn ($e) => $e->where('faculte_id', $faculteId)))
            ->orderByDesc('score')->limit(20)->get();

        $simulations = ParcoursSimulation::with('etudiant')
            ->when($faculteId !== null, fn ($q) => $q->whereHas('etudiant', fn ($e) => $e->where('faculte_id', $faculteId)))
            ->orderByDesc('id')->limit(15)->get();

        $scopeEtudiant = fn ($q) => $faculteId !== null
            ? $q->whereHas('etudiant', fn ($e) => $e->where('faculte_id', $faculteId))
            : $q;

        return view('modules.parcours-academique.tableau-bord', [
            'alertes' => $alertes,
            'simulations' => $simulations,
            'indicateurs' => [
                'etudiants' => $faculteId !== null
                    ? Etudiant::where('faculte_id', $faculteId)->count()
                    : Etudiant::count(),
                'simulations_passage' => (clone $scopeEtudiant(ParcoursSimulation::where('scenario', 'passage')))->count(),
                'simulations_passerelle' => (clone $scopeEtudiant(ParcoursSimulation::where('scenario', 'passerelle_master')))->count(),
                'equivalences_validees' => (clone $scopeEtudiant(ParcoursEquivalence::where('statut', 'validee')))->count(),
                'dispenses_validees' => (clone $scopeEtudiant(ParcoursDispense::where('statut', 'validee')))->count(),
                'reorientations' => (clone $scopeEtudiant(ParcoursReorientation::whereIn('statut', ['proposee', 'validee'])))->count(),
                'passerelles_admises' => (clone $scopeEtudiant(ParcoursPasserelle::where('statut', 'admise')))->count(),
            ],
        ]);
    }

    public function prospective(Request $request, ProspectiveParcoursService $prospective): View
    {
        Gate::authorize('parcours.view');

        $faculteId = $request->user()->faculteRestreinteId();
        $programmes = Programme::select(['id', 'nom', 'faculte_id'])
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->orderBy('nom')->limit(200)->get();
        $annees = AnneeUniversitaire::select(['id', 'libelle'])->orderByDesc('id')->limit(10)->get();

        $programmeId = $request->input('programme_id') ?? $programmes->first()?->id;
        $anneeId = $request->input('annee_universitaire_id') ?? AnneeUniversitaire::active()?->id ?? $annees->first()?->id;

        abort_unless($programmeId !== null && $anneeId !== null, 404);

        $programme = Programme::findOrFail((int) $programmeId);
        abort_unless($request->user()->peutAccederFaculte((int) $programme->faculte_id), 404);
        $annee = AnneeUniversitaire::findOrFail((int) $anneeId);

        return view('modules.parcours-academique.prospective', [
            'projection' => $prospective->projeter($programme, $annee),
            'programmes' => $programmes,
            'annees' => $annees,
            'programmeId' => (int) $programme->id,
            'anneeId' => (int) $annee->id,
        ]);
    }
}
