<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Modules\Referentiel\Models\Programme;
use App\Services\BIService;
use App\Services\Io\CsvSerializer;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * BI Center (§24) : indicateurs descriptifs avec gouvernance
 * (définition + source affichées), drill-down vers les objets,
 * périmètre faculté strict, cache versionné.
 */
class BiController extends Controller
{
    public function index(Request $request, BIService $bi): View
    {
        abort_unless($request->user()->can('statistiques.view'), 403);

        $filtres = $request->validate([
            'annee_universitaire_id' => ['nullable', 'exists:annees_universitaires,id'],
            'faculte_id' => ['nullable', 'exists:facultes,id'],
            'programme_id' => ['nullable', 'exists:programmes,id'],
        ]);

        $faculteId = $request->user()->faculteRestreinteId() ?? ($filtres['faculte_id'] ?? null);
        $anneeId = $filtres['annee_universitaire_id'] ?? AnneeUniversitaire::where('active', true)->value('id');
        $programmeId = $filtres['programme_id'] ?? null;

        return view('admin.bi.index', [
            'indicateurs' => $bi->indicateurs($anneeId, $faculteId, $programmeId),
            'cohorte' => $bi->cohorte($faculteId),
            'annees' => AnneeUniversitaire::orderByDesc('libelle')->get(),
            'facultes' => $request->user()->faculteRestreinteId() === null ? Faculte::orderBy('nom')->get() : collect(),
            'programmes' => Programme::orderBy('code')
                ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
                ->get(),
            'filtres' => ['annee_universitaire_id' => $anneeId, 'faculte_id' => $faculteId, 'programme_id' => $programmeId],
        ]);
    }

    /**
     * Export CSV du jeu d'indicateurs affiché (parité stricte
     * index/export, même périmètre, sans PII — valeurs agrégées).
     */
    public function exporter(Request $request, BIService $bi): StreamedResponse
    {
        abort_unless($request->user()->can('statistiques.export'), 403);

        $faculteId = $request->user()->faculteRestreinteId() ?? $request->input('faculte_id');
        $anneeId = $request->input('annee_universitaire_id') ?? AnneeUniversitaire::where('active', true)->value('id');
        $programmeId = $request->input('programme_id');

        $indicateurs = $bi->indicateurs($anneeId, $faculteId, $programmeId);

        return response()->streamDownload(function () use ($indicateurs) {
            $csv = app(CsvSerializer::class);
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF");
            fputcsv($sortie, $csv->neutraliserLigne(['indicateur', 'valeur', 'definition', 'source']), ';');

            foreach ($indicateurs as $nom => $indicateur) {
                $valeur = $indicateur['valeur'];
                fputcsv($sortie, $csv->neutraliserLigne([
                    $nom,
                    is_scalar($valeur) ? $valeur : json_encode($valeur, JSON_UNESCAPED_UNICODE),
                    $indicateur['definition'],
                    $indicateur['source'],
                ]), ';');
            }

            fclose($sortie);
        }, 'bi-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
