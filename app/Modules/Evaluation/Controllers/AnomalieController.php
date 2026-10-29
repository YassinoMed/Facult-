<?php

namespace App\Modules\Evaluation\Controllers;

use App\Modules\Evaluation\Services\EvaluationQualityService;
use App\Services\Io\CsvSerializer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnomalieController
{
    public function index(Request $request, EvaluationQualityService $qualityService): View
    {
        Gate::authorize('evaluations.view');

        $faculteId = $request->user()?->faculteRestreinteId();

        $filtres = [
            'gravite' => $request->query('gravite'),
            'type' => $request->query('type'),
            'recherche' => $request->query('recherche'),
            'session_id' => $request->query('session_id') ? (int) $request->query('session_id') : null,
            'epreuve_id' => $request->query('epreuve_id') ? (int) $request->query('epreuve_id') : null,
        ];

        $toutesAnomalies = $qualityService->detecterAnomalies($faculteId, $filtres);

        // LOT 03 (dette LOT 05, recalcul à chaque appel) : quand aucun
        // filtre n'est actif, la liste détectée EST la passe non filtrée —
        // les KPI en dérivent sans seconde détection complète. Avec
        // filtres (gravité / type / recherche / session / épreuve), la
        // passe dédiée au périmètre global reste nécessaire : les filtres
        // session/épreuve sont appliqués en requête non dérivable.
        $passeUnique = empty($filtres['gravite']) && empty($filtres['type'])
            && empty($filtres['recherche']) && empty($filtres['session_id'])
            && empty($filtres['epreuve_id']);

        $kpis = $passeUnique
            ? $qualityService->kpisDepuisAnomalies($toutesAnomalies)
            : $qualityService->kpis($faculteId);

        // Pagination en mémoire
        $page = (int) $request->query('page', 1);
        $parPage = 25;
        $offset = ($page - 1) * $parPage;
        $elementsPage = array_slice($toutesAnomalies, $offset, $parPage);

        $anomalies = new LengthAwarePaginator(
            $elementsPage,
            count($toutesAnomalies),
            $parPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('modules.evaluation.anomalies-index', [
            'anomalies' => $anomalies,
            'kpis' => $kpis,
            'filtres' => $filtres,
        ]);
    }

    public function resoudreGroupee(Request $request, EvaluationQualityService $qualityService): RedirectResponse
    {
        Gate::authorize('notes_eval.validate');

        $request->validate([
            'anomalie_ids' => 'required|array|min:1',
            'anomalie_ids.*' => 'required|string',
            'action' => 'required|in:synchroniser,valider',
        ]);

        $resultat = $qualityService->resoudreGroupee(
            $request->input('anomalie_ids'),
            $request->user(),
            $request->input('action')
        );

        $message = "Action groupée terminée : {$resultat['resolus']} anomalie(s) résolue(s).";
        if ($resultat['ignores'] > 0) {
            $message .= " ({$resultat['ignores']} ignorée(s))";
        }

        if (! empty($resultat['erreurs'])) {
            return back()->with('succes', $message)->withErrors(['action' => implode(' | ', $resultat['erreurs'])]);
        }

        return back()->with('succes', $message);
    }

    public function exporter(Request $request, EvaluationQualityService $qualityService): StreamedResponse
    {
        Gate::authorize('evaluations.view');

        $faculteId = $request->user()?->faculteRestreinteId();
        $filtres = [
            'gravite' => $request->query('gravite'),
            'type' => $request->query('type'),
            'recherche' => $request->query('recherche'),
        ];

        $anomalies = $qualityService->detecterAnomalies($faculteId, $filtres);

        return response()->streamDownload(function () use ($anomalies) {
            $csv = app(CsvSerializer::class);
            $sortie = fopen('php://output', 'w');
            fputcsv($sortie, $csv->neutraliserLigne(['ID', 'Type', 'Gravité', 'Périmètre', 'Étudiant / Réf', 'Libellé', 'Date Détection']));

            foreach ($anomalies as $a) {
                fputcsv($sortie, $csv->neutraliserLigne([
                    $a['id'],
                    $a['type'],
                    $a['gravite'],
                    $a['perimetre'],
                    $a['etudiant_ref'] ?? '',
                    $a['libelle'],
                    $a['date_detection'],
                ]));
            }

            fclose($sortie);
        }, 'anomalies-evaluations-'.date('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv']);
    }
}
