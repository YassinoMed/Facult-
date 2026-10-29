<?php

namespace App\Modules\Evaluation\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ImportReport;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\EvaluationDocumentBatchService;
use App\Modules\Schedule\Models\Group;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DocumentBatchController extends Controller
{
    public function __construct(
        private readonly EvaluationDocumentBatchService $batchService,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('evaluations.view');

        $user = $request->user();
        $faculteId = $user->faculteRestreinteId();

        $sessions = SessionExamen::query()
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $groupes = Group::query()
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->orderBy('code')
            ->get();

        $recentsRapports = ImportReport::where('type', 'batch_documents')
            ->when($faculteId !== null, fn ($q) => $q->where('user_id', $user->id))
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return view('modules.evaluation.documents-batch', [
            'sessions' => $sessions,
            'groupes' => $groupes,
            'typesDocuments' => EvaluationDocumentBatchService::TYPES_DOCUMENTS,
            'recentsRapports' => $recentsRapports,
        ]);
    }

    public function previsualiser(Request $request): JsonResponse
    {
        Gate::authorize('evaluations.view');

        $validated = $request->validate([
            'session_id' => ['nullable', 'integer'],
            'groupe_id' => ['nullable', 'integer'],
            'epreuve_id' => ['nullable', 'integer'],
            'deliberation_id' => ['nullable', 'integer'],
            'types' => ['required', 'array', 'min:1'],
            'types.*' => ['string', 'in:'.implode(',', array_keys(EvaluationDocumentBatchService::TYPES_DOCUMENTS))],
        ]);

        $preview = $this->batchService->previsualiser($validated, $request->user());

        return response()->json([
            'succes' => true,
            'nombre_epreuves' => $preview['nombre_epreuves'],
            'nombre_deliberations' => $preview['nombre_deliberations'],
            'nombre_candidats' => $preview['nombre_candidats'],
            'nombre_documents_estimes' => $preview['nombre_documents_estimes'],
            'avertissements' => $preview['avertissements'],
        ]);
    }

    public function generer(Request $request): RedirectResponse
    {
        Gate::authorize('evaluations.create');

        $validated = $request->validate([
            'session_id' => ['nullable', 'integer'],
            'groupe_id' => ['nullable', 'integer'],
            'epreuve_id' => ['nullable', 'integer'],
            'deliberation_id' => ['nullable', 'integer'],
            'types' => ['required', 'array', 'min:1'],
            'types.*' => ['string', 'in:'.implode(',', array_keys(EvaluationDocumentBatchService::TYPES_DOCUMENTS))],
        ]);

        try {
            $resultat = $this->batchService->genererLot($validated, $request->user());

            if ($resultat['reussis'] === 0) {
                return redirect()->route('admin.evaluations.documents-batch.index')
                    ->with('error', 'Aucun document généré : '.implode('; ', $resultat['erreurs']));
            }

            $message = sprintf('Lot généré avec succès : %d document(s) archivé(s).', $resultat['reussis']);
            if (! empty($resultat['erreurs'])) {
                $message .= ' ('.count($resultat['erreurs']).' erreur(s) mineure(s))';
            }

            return redirect()->route('admin.evaluations.documents-batch.index')
                ->with('status', $message)
                ->with('lot_telechargeable', $resultat['lot_id']);
        } catch (\Throwable $e) {
            return redirect()->route('admin.evaluations.documents-batch.index')
                ->with('error', 'Échec de la génération : '.$e->getMessage());
        }
    }

    public function telecharger(Request $request, string $lotId): BinaryFileResponse|RedirectResponse
    {
        Gate::authorize('evaluations.view');

        try {
            return $this->batchService->telechargerLot($lotId, $request->user());
        } catch (DomainException $e) {
            return redirect()->route('admin.evaluations.documents-batch.index')
                ->with('error', $e->getMessage());
        }
    }
}
