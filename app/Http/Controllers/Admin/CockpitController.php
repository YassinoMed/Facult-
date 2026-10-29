<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ElementRecent;
use App\Models\HistoriqueAcademique;
use App\Modules\Calendar\Models\EvenementCalendrier;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Modules\Tasks\Models\Task;
use App\Services\Io\CsvSerializer;
use App\Services\Kpi\CockpitKpiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cockpit administratif (§8) : ce qui nécessite une intervention
 * humaine MAINTENANT + indicateurs de scolarité unifiés (LOT 03).
 * Composition de données existantes (tâches, agenda, audit, qualité, KPI)
 * — aucune table cockpit dédiée.
 */
class CockpitController extends Controller
{
    public function index(Request $request, CockpitKpiService $kpiService): View
    {
        Gate::authorize('cockpit.view');

        $faculteId = $request->user()->faculteRestreinteId();
        $tachesBase = Task::query()
            ->when($faculteId !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('faculte_id')->orWhere('faculte_id', $faculteId)));

        $aTraiter = (clone $tachesBase)->whereIn('statut', ['pending', 'in_progress', 'waiting'])->count();
        $urgentes = (clone $tachesBase)->where('priorite', 'critical')
            ->whereIn('statut', ['pending', 'in_progress', 'waiting', 'expired'])->orderBy('echeance_a')->limit(8)->get();
        $miennes = (clone $tachesBase)->where('assigne_a', $request->user()->id)
            ->whereIn('statut', ['pending', 'in_progress', 'waiting'])->orderBy('echeance_a')->limit(8)->get();

        $aujourdhui = EvenementCalendrier::where('statut', 'publie')
            ->whereDate('date_debut', '<=', now()->format('Y-m-d'))
            ->where(fn ($q) => $q->whereNull('date_fin')->orWhereDate('date_fin', '>=', now()->format('Y-m-d')))
            ->when($faculteId !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('faculte_id')->orWhere('faculte_id', $faculteId)))
            ->orderBy('date_debut')->limit(8)->get();

        $demandesEcheance = $request->user()->can('demandes.view')
            ? OnlineRequest::with(['etudiant', 'type'])
                ->whereIn('status', ['submitted', 'received', 'in_review'])
                ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
                ->get()->filter(fn ($d) => $d->enRetard())->take(8)
            : collect();

        $activite = HistoriqueAcademique::with(['etudiant', 'utilisateur'])
            ->when($faculteId !== null, fn ($q) => $q->whereHas('etudiant', fn ($e) => $e->where('faculte_id', $faculteId)))
            ->latest()->limit(10)->get();

        $snapshotKpi = $kpiService->calculerSnapshot(null, $faculteId, null, $request->user());

        return view('admin.cockpit.index', [
            'aTraiter' => $aTraiter,
            'urgentes' => $urgentes,
            'miennes' => $miennes,
            'aujourdhui' => $aujourdhui,
            'demandesEcheance' => $demandesEcheance,
            'activite' => $activite,
            'recents' => ElementRecent::where('user_id', $request->user()->id)->latest('updated_at')->limit(6)->get(),
            'snapshotKpi' => $snapshotKpi,
        ]);
    }

    /**
     * Endpoint API JSON fournissant les KPI du cockpit au format structuré.
     */
    public function kpiJson(Request $request, CockpitKpiService $kpiService): JsonResponse
    {
        Gate::authorize('cockpit.view');

        $anneeId = $request->has('annee_id') ? (int) $request->input('annee_id') : null;
        $faculteId = $request->has('faculte_id') ? (int) $request->input('faculte_id') : null;
        $programmeId = $request->has('programme_id') ? (int) $request->input('programme_id') : null;

        $snapshot = $kpiService->calculerSnapshot($anneeId, $faculteId, $programmeId, $request->user());

        return response()->json($snapshot->toArray());
    }

    /**
     * Export CSV des KPI du cockpit scolarité (respect strict des périmètres).
     */
    public function exporterKpi(Request $request, CockpitKpiService $kpiService): StreamedResponse
    {
        Gate::authorize('cockpit.view');

        $anneeId = $request->has('annee_id') ? (int) $request->input('annee_id') : null;
        $faculteId = $request->has('faculte_id') ? (int) $request->input('faculte_id') : null;
        $programmeId = $request->has('programme_id') ? (int) $request->input('programme_id') : null;

        $snapshot = $kpiService->calculerSnapshot($anneeId, $faculteId, $programmeId, $request->user());

        return response()->streamDownload(function () use ($snapshot) {
            $csv = app(CsvSerializer::class);
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF");
            fputcsv($sortie, $csv->neutraliserLigne([
                'code', 'nom', 'categorie', 'valeur', 'unite',
                'statut_alerte', 'statut_fraicheur', 'derniere_maj',
                'definition', 'formule', 'source_verite',
            ]), ';');

            foreach ($snapshot->indicateurs as $ind) {
                fputcsv($sortie, $csv->neutraliserLigne([
                    $ind->definition->code,
                    $ind->definition->nom,
                    $ind->definition->categorie,
                    $ind->valeurFormatee,
                    $ind->definition->unite,
                    $ind->statutAlerte,
                    $ind->statutFraicheur,
                    $ind->derniereMaj ?? 'N/A',
                    $ind->definition->definition,
                    $ind->definition->formule,
                    $ind->definition->sourceVerite,
                ]), ';');
            }

            fclose($sortie);
        }, 'cockpit-kpi-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
