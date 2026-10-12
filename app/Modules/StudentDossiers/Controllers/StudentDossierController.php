<?php

namespace App\Modules\StudentDossiers\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Models\HistoriqueAcademique;
use App\Modules\StudentDossiers\Models\StudentDossier;
use App\Modules\StudentDossiers\Requests\StoreStudentDossierRequest;
use App\Modules\StudentDossiers\Services\Pdf\PdfDocumentService;
use App\Modules\StudentDossiers\Services\StudentDossierService;
use App\Support\Recherche;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Dossiers permanents — back-office (§13) : recherche, pagination,
 * filtres, consultation. Eager loading systématique, jamais de N+1.
 */
class StudentDossierController extends Controller
{
    public function __construct(
        private readonly StudentDossierService $dossiers,
        private readonly PdfDocumentService $pdfs,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', StudentDossier::class);

        $query = StudentDossier::with(['etudiant.faculte'])->withCount('documents');

        if ($faculteId = $request->user()->faculteRestreinteId()) {
            $query->whereHas('etudiant', fn ($q) => $q->where('faculte_id', $faculteId));
        } elseif ($filtreFaculte = $request->input('faculte_id')) {
            $query->whereHas('etudiant', fn ($q) => $q->where('faculte_id', $filtreFaculte));
        }

        if ($search = trim($request->input('search', ''))) {
            $motif = Recherche::contient($search);
            $query->where(function ($q) use ($motif) {
                $q->where('reference', 'like', $motif)
                    ->orWhereHas('etudiant', fn ($e) => $e
                        ->where('nom', 'like', $motif)
                        ->orWhere('prenom', 'like', $motif)
                        ->orWhere('cin', 'like', $motif)
                        ->orWhere('email', 'like', $motif)
                        ->orWhere('num_inscription', 'like', $motif));
            });
        }

        if ($statut = $request->input('statut')) {
            $query->where('status', $statut);
        }

        if ($niveau = $request->input('niveau')) {
            $query->whereHas('etudiant', fn ($q) => $q->where('niveau', $niveau));
        }

        $dossiers = $query->latest()->paginate(12)->withQueryString();

        return view('modules.student-dossiers.index', ['dossiers' => $dossiers]);
    }

    public function show(Request $request, StudentDossier $dossier): View
    {
        $dossier->loadMissing('etudiant.faculte');

        abort_unless($request->user()->peutAccederFaculte((int) $dossier->etudiant->faculte_id), 404);
        Gate::authorize('view', $dossier);

        $dossier->load(['documents' => fn ($q) => $q->latest()->limit(100)]);

        $peutVoirAudit = Gate::allows('student_documents.audit');
        $audit = $peutVoirAudit
            ? HistoriqueAcademique::where('etudiant_id', $dossier->etudiant_id)
                ->where('action', 'like', 'student_document.%')
                ->orderByDesc('created_at')
                ->limit(30)
                ->get()
            : collect();

        return view('modules.student-dossiers.show', [
            'dossier' => $dossier,
            'peutVoirAudit' => $peutVoirAudit,
            'audit' => $audit,
            'pdf' => $this->pdfs->dernierPdf($dossier),
        ]);
    }

    public function store(StoreStudentDossierRequest $request): RedirectResponse
    {
        /** @var Etudiant $etudiant */
        $etudiant = Etudiant::findOrFail($request->validated('etudiant_id'));

        abort_unless($request->user()->peutAccederFaculte((int) $etudiant->faculte_id), 404);

        $dossier = $this->dossiers->obtenirOuCreer($etudiant);

        return redirect()->route('admin.student-dossiers.show', $dossier)
            ->with('success', "Dossier {$dossier->reference} prêt.");
    }

    public function destroy(Request $request, StudentDossier $dossier): RedirectResponse
    {
        $dossier->loadMissing(['etudiant', 'documents.versions']);

        abort_unless($request->user()->peutAccederFaculte((int) $dossier->etudiant->faculte_id), 404);
        Gate::authorize('delete', $dossier);

        // Fichiers physiques avec les lignes (jamais d'orphelins PII).
        DB::transaction(function () use ($dossier) {
            foreach ($dossier->documents as $document) {
                foreach ($document->versions as $version) {
                    Storage::disk('private')->delete($version->storage_path);
                }
                Storage::disk('private')->delete($document->storage_path);
            }
            $dossier->delete();
        });

        return redirect()->route('admin.student-dossiers.index')
            ->with('success', "Dossier {$dossier->reference} supprimé avec ses documents.");
    }
}
