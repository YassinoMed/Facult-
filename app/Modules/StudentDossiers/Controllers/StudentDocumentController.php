<?php

namespace App\Modules\StudentDossiers\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\StudentDossiers\Models\StudentDocument;
use App\Modules\StudentDossiers\Models\StudentDocumentVersion;
use App\Modules\StudentDossiers\Models\StudentDossier;
use App\Modules\StudentDossiers\Requests\RejectStudentDocumentRequest;
use App\Modules\StudentDossiers\Requests\ReplaceStudentDocumentRequest;
use App\Modules\StudentDossiers\Requests\UploadStudentDocumentRequest;
use App\Modules\StudentDossiers\Services\StudentDocumentService;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documents — back-office (§7/§9) : upload, remplacement versionné,
 * validation/rejet, suppression, téléchargement audité. Contrôleur
 * fin : toute la logique vit dans StudentDocumentService.
 */
class StudentDocumentController extends Controller
{
    public function __construct(
        private readonly StudentDocumentService $documents,
        private readonly AuditService $audit,
    ) {}

    public function store(UploadStudentDocumentRequest $request, StudentDossier $dossier): RedirectResponse
    {
        $dossier->loadMissing('etudiant');

        abort_unless($request->user()->peutAccederFaculte((int) $dossier->etudiant->faculte_id), 404);
        Gate::authorize('upload', [StudentDocument::class, $dossier->etudiant]);

        $donnees = $request->validated();

        try {
            $this->documents->upload(
                $dossier,
                $donnees,
                $request->file('document'),
                $request->user()->id,
            );
        } catch (\RuntimeException $e) {
            return redirect()->back()->withErrors(['document' => $e->getMessage()])->withInput();
        }

        return redirect()->route('admin.student-dossiers.show', $dossier)
            ->with('success', 'Document téléversé (en attente de validation).');
    }

    public function replace(ReplaceStudentDocumentRequest $request, StudentDocument $document): RedirectResponse
    {
        $document->loadMissing('dossier.etudiant');

        abort_unless($request->user()->peutAccederFaculte((int) $document->dossier->etudiant->faculte_id), 404);
        Gate::authorize('replace', $document);

        try {
            $this->documents->replace($document, $request->file('document'), $request->user()->id);
        } catch (\RuntimeException $e) {
            return redirect()->back()->withErrors(['document' => $e->getMessage()])->withInput();
        }

        return redirect()->route('admin.student-dossiers.show', $document->dossier)
            ->with('success', 'Document remplacé (nouvelle version, en attente de validation).');
    }

    public function validate(Request $request, StudentDocument $document): RedirectResponse
    {
        $document->loadMissing('dossier.etudiant');

        abort_unless($request->user()->peutAccederFaculte((int) $document->dossier->etudiant->faculte_id), 404);
        Gate::authorize('validate', $document);

        $this->documents->validate($document, $request->user()->id);

        return redirect()->route('admin.student-dossiers.show', $document->dossier)
            ->with('success', 'Document validé.');
    }

    public function reject(RejectStudentDocumentRequest $request, StudentDocument $document): RedirectResponse
    {
        $document->loadMissing('dossier.etudiant');

        abort_unless($request->user()->peutAccederFaculte((int) $document->dossier->etudiant->faculte_id), 404);
        Gate::authorize('reject', $document);

        $this->documents->reject($document, $request->validated('motif'), $request->user()->id);

        return redirect()->route('admin.student-dossiers.show', $document->dossier)
            ->with('success', 'Document rejeté (motif transmis).');
    }

    public function destroy(Request $request, StudentDocument $document): RedirectResponse
    {
        $document->loadMissing('dossier.etudiant');
        $dossier = $document->dossier;

        abort_unless($request->user()->peutAccederFaculte((int) $document->dossier->etudiant->faculte_id), 404);
        Gate::authorize('delete', $document);

        $this->documents->delete($document);

        return redirect()->route('admin.student-dossiers.show', $dossier)
            ->with('success', 'Document supprimé définitivement.');
    }

    /**
     * Téléchargement (§2/§5/§7) : auth → policy → scope → audit →
     * réponse au MIME persisté du document. Le chemin interne n'est
     * jamais exposé.
     */
    public function download(Request $request, StudentDossier $dossier, StudentDocument $document): StreamedResponse
    {
        abort_if($document->student_dossier_id !== $dossier->id, 404);

        $dossier->loadMissing('etudiant');

        abort_unless($request->user()->peutAccederFaculte((int) $dossier->etudiant->faculte_id), 404);
        Gate::authorize('download', $document);

        $this->audit->tracer(
            $dossier->etudiant,
            'student_document.downloaded',
            'student_document',
            $document->id,
        );

        return $this->documents->reponseTelechargement($document);
    }

    /**
     * Aperçu en ligne d'un document (§4/LOOP 05) : disposition inline, nosniff.
     */
    public function preview(Request $request, StudentDossier $dossier, StudentDocument $document): StreamedResponse
    {
        abort_if($document->student_dossier_id !== $dossier->id, 404);

        $dossier->loadMissing('etudiant');

        abort_unless($request->user()->peutAccederFaculte((int) $dossier->etudiant->faculte_id), 404);
        Gate::authorize('download', $document);

        $this->audit->tracer(
            $dossier->etudiant,
            'student_document.previewed',
            'student_document',
            $document->id,
        );

        return $this->documents->reponseApercu($document);
    }

    /**
     * Téléchargement d'une version explicite (§3) : mêmes gardes que le
     * document courant, MIME = celui de LA VERSION demandée.
     */
    public function downloadVersion(
        Request $request,
        StudentDossier $dossier,
        StudentDocument $document,
        StudentDocumentVersion $version,
    ): StreamedResponse {
        abort_if($document->student_dossier_id !== $dossier->id, 404);
        abort_if($version->student_document_id !== $document->id, 404);

        $dossier->loadMissing('etudiant');

        abort_unless($request->user()->peutAccederFaculte((int) $dossier->etudiant->faculte_id), 404);
        Gate::authorize('download', $document);

        $this->audit->tracer(
            $dossier->etudiant,
            'student_document.downloaded',
            'student_document',
            $document->id,
            null,
            ['version' => $version->version],
        );

        return $this->documents->reponseTelechargement($document, $version);
    }
}
