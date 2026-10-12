<?php

namespace App\Modules\StudentDossiers\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Modules\StudentDossiers\Models\GeneratedDocument;
use App\Modules\StudentDossiers\Services\DocumentStorageService;
use App\Modules\StudentDossiers\Services\Pdf\OfficialDocumentService;
use App\Modules\StudentDossiers\Services\StudentDossierService;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documents officiels (Phase 7) : génération versionnée, téléchargement
 * gated + audité, révocation. Contrôleur fin — métier dans le service.
 * Réutilise la policy `student_dossiers.pdf` (zéro permission créée).
 */
class OfficialDocumentController extends Controller
{
    public function __construct(
        private readonly OfficialDocumentService $officiels,
        private readonly DocumentStorageService $stockage,
    ) {}

    public function generer(Request $request, Etudiant $etudiant): RedirectResponse
    {
        $donnees = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', array_keys(OfficialDocumentService::catalogue()))],
            'inscription_id' => ['required', 'integer', 'exists:inscriptions_academiques,id'],
            'semestre' => ['nullable', 'integer', 'in:1,2'],
        ]);

        Gate::authorize('update', $etudiant);
        Gate::authorize('generatePdf', $this->dossierOu403($etudiant));

        try {
            $document = $this->officiels->generer($etudiant, $donnees['type'], (int) $donnees['inscription_id'], (int) $request->user()->id, $donnees['semestre'] ?? null);
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['document' => $e->getMessage()]);
        }

        return redirect()->route('admin.documents-officiels.download', $document)
            ->with('success', "Document {$document->reference} généré (v{$document->version}).");
    }

    public function download(Request $request, GeneratedDocument $document): StreamedResponse
    {
        $document->loadMissing('dossier.etudiant');
        Gate::authorize('downloadPdf', $document->dossier);

        abort_if($document->statut === 'revoque', 410, 'Document révoqué.');

        app(AuditService::class)->tracer(
            $document->dossier->etudiant, 'telechargement_document', 'document', $document->id,
            null, ['reference' => $document->reference],
        );

        return response()->stream(function () use ($document) {
            $flux = $this->stockage->lireFlux($document->storage_path);
            fpassthru($flux);
            fclose($flux);
        }, 200, [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'attachment; filename="'.$this->stockage->nomTelechargementSecurise($document->reference.'.pdf').'"',
        ]);
    }

    public function revoquer(Request $request, GeneratedDocument $document): RedirectResponse
    {
        $document->loadMissing('dossier.etudiant');
        Gate::authorize('generatePdf', $document->dossier);

        $motif = $request->validate(['motif' => 'required|string|max:255'])['motif'];

        $this->officiels->revoquer($document, $motif, (int) $request->user()->id);

        return redirect()->back()->with('success', "Document {$document->reference} révoqué (traçable).");
    }

    private function dossierOu403(Etudiant $etudiant): object
    {
        $dossier = app(StudentDossierService::class)->obtenirOuCreer($etudiant);

        abort_unless($dossier !== null, 403);

        return $dossier;
    }
}
