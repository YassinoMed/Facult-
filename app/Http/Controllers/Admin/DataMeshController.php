<?php

namespace App\Http\Controllers\Admin;

use App\DataMesh\Observabilite\ServiceObservabilite;
use App\Http\Controllers\Controller;
use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Data Mesh Observability Center : tableau de bord en lecture seule du
 * Data Mesh (graphe, flux, qualité, outbox). Aucun traitement métier
 * déclenché, aucune écriture. Périmètre facultaire forcé côté serveur.
 */
class DataMeshController extends Controller
{
    public function index(Request $request, ServiceObservabilite $observabilite): View
    {
        abort_unless($request->user()->can('statistiques.view'), 403);

        $filtres = $request->validate([
            'faculte_id' => ['nullable', 'integer', 'exists:facultes,id'],
            'annee_id' => ['nullable', 'integer', 'exists:annees_universitaires,id'],
        ]);

        $utilisateur = $request->user();

        try {
            $faculteId = $observabilite->perimetreEffectif($utilisateur, isset($filtres['faculte_id']) ? (int) $filtres['faculte_id'] : null);
        } catch (AuthorizationException) {
            abort(403, 'Faculté hors périmètre.');
        }

        $anneeId = isset($filtres['annee_id']) ? (int) $filtres['annee_id'] : (int) (AnneeUniversitaire::active()?->id ?? 0);

        return view('admin.datamesh.index', [
            'apercuInitial' => $observabilite->apercu($utilisateur, $faculteId, $anneeId ?: null),
            'annees' => AnneeUniversitaire::orderByDesc('libelle')->get(['id', 'libelle']),
            'facultes' => $utilisateur->faculteRestreinteId() === null ? Faculte::orderBy('nom')->get(['id', 'nom']) : collect(),
            'filtres' => ['faculte_id' => $faculteId, 'annee_id' => $anneeId ?: null],
            'estRestreint' => $utilisateur->faculteRestreinteId() !== null,
        ]);
    }

    public function graphe(Request $request, ServiceObservabilite $observabilite): JsonResponse
    {
        abort_unless($request->user()->can('statistiques.view'), 403);
        $filtres = $this->validerFiltres($request);

        try {
            return response()->json($observabilite->graphe($request->user(), $filtres['faculte_id'], $filtres['annee_id']));
        } catch (AuthorizationException) {
            return response()->json(['ok' => false, 'code' => 'hors_perimetre', 'message' => 'Faculté hors périmètre.'], 403);
        }
    }

    public function apercu(Request $request, ServiceObservabilite $observabilite): JsonResponse
    {
        abort_unless($request->user()->can('statistiques.view'), 403);
        $filtres = $this->validerFiltres($request);

        try {
            return response()->json($observabilite->apercu($request->user(), $filtres['faculte_id'], $filtres['annee_id']));
        } catch (AuthorizationException) {
            return response()->json(['ok' => false, 'code' => 'hors_perimetre', 'message' => 'Faculté hors périmètre.'], 403);
        }
    }

    public function qualite(Request $request, ServiceObservabilite $observabilite): JsonResponse
    {
        abort_unless($request->user()->can('statistiques.view'), 403);
        $filtres = $this->validerFiltres($request);

        try {
            return response()->json($observabilite->qualite($request->user(), $filtres['faculte_id'], $filtres['annee_id']));
        } catch (AuthorizationException) {
            return response()->json(['ok' => false, 'code' => 'hors_perimetre', 'message' => 'Faculté hors périmètre.'], 403);
        }
    }

    public function outbox(Request $request, ServiceObservabilite $observabilite): JsonResponse
    {
        abort_unless($request->user()->can('statistiques.view'), 403);
        $filtres = $this->validerFiltres($request);

        try {
            return response()->json($observabilite->outbox($request->user(), $filtres['faculte_id']));
        } catch (AuthorizationException) {
            return response()->json(['ok' => false, 'code' => 'hors_perimetre', 'message' => 'Faculté hors périmètre.'], 403);
        }
    }

    public function flux(Request $request, ServiceObservabilite $observabilite): JsonResponse
    {
        abort_unless($request->user()->can('statistiques.view'), 403);
        $filtres = $this->validerFiltres($request);

        try {
            return response()->json($observabilite->flux($request->user(), $filtres['faculte_id']));
        } catch (AuthorizationException) {
            return response()->json(['ok' => false, 'code' => 'hors_perimetre', 'message' => 'Faculté hors périmètre.'], 403);
        }
    }

    public function noeud(Request $request, ServiceObservabilite $observabilite, string $id): JsonResponse
    {
        abort_unless($request->user()->can('statistiques.view'), 403);
        $filtres = $this->validerFiltres($request);

        if (! preg_match('/^[a-z_]+:[a-z0-9_.-]+$/i', $id)) {
            return response()->json(['ok' => false, 'code' => 'noeud_invalide', 'message' => 'Identifiant de nœud invalide.'], 422);
        }

        try {
            $detail = $observabilite->detailNoeud($request->user(), $id, $filtres['faculte_id'], $filtres['annee_id']);
        } catch (AuthorizationException) {
            return response()->json(['ok' => false, 'code' => 'hors_perimetre', 'message' => 'Faculté hors périmètre.'], 403);
        }

        if ($detail === null) {
            return response()->json(['ok' => false, 'code' => 'noeud_inconnu', 'message' => 'Nœud inconnu.'], 404);
        }

        return response()->json($detail);
    }

    /** @return array{faculte_id: ?int, annee_id: ?int} */
    private function validerFiltres(Request $request): array
    {
        $valides = $request->validate([
            'faculte_id' => ['nullable', 'integer', 'exists:facultes,id'],
            'annee_id' => ['nullable', 'integer', 'exists:annees_universitaires,id'],
        ]);

        return [
            'faculte_id' => isset($valides['faculte_id']) ? (int) $valides['faculte_id'] : null,
            'annee_id' => isset($valides['annee_id']) ? (int) $valides['annee_id'] : null,
        ];
    }
}
