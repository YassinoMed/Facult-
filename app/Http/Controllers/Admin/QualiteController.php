<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AnomalieQualite;
use App\Models\Etudiant;
use App\Services\AgregateurQualite;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Data Quality Center (§19.1) : cockpit agrégé des détecteurs
 * existants + anomalies persistées pilotables (acquittement,
 * assignation, résolution). Score documenté : % d'anomalies
 * résolues ou ignorées sur le total connu.
 */
class QualiteController extends Controller
{
    public function index(Request $request, AgregateurQualite $agregateur): View
    {
        Gate::authorize('viewAny', AnomalieQualite::class);

        $faculteId = $request->user()->faculteRestreinteId();

        $query = AnomalieQualite::orderByRaw("CASE gravite WHEN 'critique' THEN 0 WHEN 'erreur' THEN 1 WHEN 'avertissement' THEN 2 ELSE 3 END")
            ->latest('derniere_detection_a')
            ->when($request->input('statut'), fn ($q, $s) => $q->where('statut', $s))
            ->when($request->input('gravite'), fn ($q, $g) => $q->where('gravite', $g))
            ->when($request->input('source'), fn ($q, $s) => $q->where('source', $s));

        $total = (clone $query)->count();
        $resolues = (clone $query)->whereIn('statut', ['resolue', 'ignoree'])->count();

        return view('admin.qualite.index', [
            'anomalies' => $query->paginate(20)->withQueryString(),
            'score' => $total > 0 ? (int) round($resolues / $total * 100) : 100,
            'total' => $total,
            'temps_reel' => $request->boolean('temps_reel') ? $agregateur->detecter($faculteId, 60) : [],
        ]);
    }

    public function transiter(Request $request, AnomalieQualite $anomalie): RedirectResponse
    {
        Gate::authorize('update', $anomalie);

        $cible = $request->validate([
            'statut' => ['required', 'in:acquittee,en_cours,resolue,ignoree,ouverte'],
            'resolution' => ['nullable', 'string', 'max:500'],
        ])['statut'];

        if (! $anomalie->peutTransiterVers($cible)) {
            return back()->withErrors(['anomalie' => "Transition {$anomalie->statut} → {$cible} interdite."]);
        }

        $ancienStatut = $anomalie->statut;

        $anomalie->update([
            'statut' => $cible,
            'resolution' => $request->input('resolution', $anomalie->resolution),
            'resolue_par' => in_array($cible, ['resolue', 'ignoree'], true) ? $request->user()->id : $anomalie->resolue_par,
            'resolue_a' => in_array($cible, ['resolue', 'ignoree'], true) ? now() : $anomalie->resolue_a,
        ]);

        if ($anomalie->entite_type === 'etudiant') {
            $etudiant = Etudiant::find($anomalie->entite_id);
            if ($etudiant !== null) {
                app(AuditService::class)->tracer(
                    $etudiant,
                    'qualite.anomalie_resolue',
                    'anomalie_qualite',
                    $anomalie->id,
                    ['statut' => $ancienStatut],
                    ['statut' => $cible, 'resolution' => $request->input('resolution')],
                    'web',
                );
            }
        }

        Log::info('qualite.anomalie.statut_modifie', [
            'anomalie_id' => $anomalie->id,
            'ancien_statut' => $ancienStatut,
            'nouveau_statut' => $cible,
            'agent_id' => $request->user()->id,
        ]);

        return back()->with('success', "Anomalie passée à « {$cible} ».");
    }

    public function assigner(Request $request, AnomalieQualite $anomalie): RedirectResponse
    {
        Gate::authorize('update', $anomalie);

        $request->validate(['assignee_a' => ['nullable', 'exists:users,id']]);

        $anomalie->update(['assignee_a' => $request->input('assignee_a')]);

        return back()->with('success', 'Assignation enregistrée.');
    }
}
