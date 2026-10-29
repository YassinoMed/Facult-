<?php

namespace App\Modules\Evaluation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\NoteEpreuve;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\EvaluationAuditService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class HistoriqueNotesController extends Controller
{
    public function __construct(
        private readonly EvaluationAuditService $auditService,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('evaluations.view');

        $filtres = $request->only(['session_id', 'epreuve_id', 'etudiant_id', 'auteur_id', 'recherche', 'date_debut', 'date_fin']);
        $journal = $this->auditService->journalGlobal($filtres, $request->user(), 20);

        $faculteId = $request->user()->faculteRestreinteId();
        $sessions = SessionExamen::query()
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return view('modules.evaluation.notes-historique', [
            'journal' => $journal,
            'sessions' => $sessions,
            'filtres' => $filtres,
        ]);
    }

    public function detail(Request $request, NoteEpreuve $note): View
    {
        Gate::authorize('evaluations.view');

        $donnees = $this->auditService->historiqueNote($note, $request->user());

        return view('modules.evaluation.notes-historique-detail', $donnees);
    }

    public function epreuve(Request $request, Epreuve $epreuve): View
    {
        Gate::authorize('evaluations.view');

        $stats = $this->auditService->statistiquesEpreuve($epreuve, $request->user());
        $filtres = ['epreuve_id' => $epreuve->id];
        $journal = $this->auditService->journalGlobal($filtres, $request->user(), 30);

        return view('modules.evaluation.notes-historique-epreuve', [
            'epreuve' => $epreuve,
            'journal' => $journal,
            'stats' => $stats,
        ]);
    }

    public function comparer(Request $request, NoteEpreuve $note): JsonResponse
    {
        Gate::authorize('evaluations.view');

        $validated = $request->validate([
            'version_a' => ['required', 'integer'],
            'version_b' => ['required', 'integer'],
        ]);

        try {
            $diff = $this->auditService->comparerVersions(
                $note,
                $validated['version_a'],
                $validated['version_b'],
                $request->user()
            );

            return response()->json(['succes' => true, 'comparaison' => $diff]);
        } catch (DomainException $e) {
            return response()->json(['succes' => false, 'message' => $e->getMessage()], 403);
        }
    }
}
