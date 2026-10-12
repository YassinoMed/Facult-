<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AnneeUniversitaire;
use App\Services\PopulationStatsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Population : statistiques et qualité des données (§40-41).
 */
class PopulationController extends Controller
{
    public function __construct(
        private readonly PopulationStatsService $stats,
    ) {}

    public function stats(Request $request): View
    {
        $anneeId = $request->input('annee_universitaire_id') !== null
            && $request->input('annee_universitaire_id') !== ''
            ? (int) $request->input('annee_universitaire_id') : null;

        return view('admin.population.stats', [
            'stats' => $this->stats->statistiques($request->user()->faculteRestreinteId(), $anneeId),
            'ventilations' => $this->stats->ventilations($request->user()->faculteRestreinteId()),
            'anneeId' => $anneeId,
            'annees' => AnneeUniversitaire::orderByDesc('date_debut')->get(),
        ]);
    }

    public function qualite(Request $request): View
    {
        return view('admin.population.qualite', [
            'anomalies' => $this->stats->anomalies($request->user()->faculteRestreinteId(), 200),
        ]);
    }
}
