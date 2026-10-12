<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDocumentAcademiqueRequest;
use App\Models\AnneeUniversitaire;
use App\Models\DocumentAcademique;
use App\Models\DossierAcademique;
use App\Models\HistoriqueAcademique;
use App\Services\AuditService;
use App\Services\DocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class DocumentAcademiqueController extends Controller
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly AuditService $audit,
    ) {}

    public function store(StoreDocumentAcademiqueRequest $request, DossierAcademique $dossier): RedirectResponse
    {
        // Uploader un document = droit de modification sur le dossier
        // parent, scopé faculté (anti-IDOR).
        Gate::authorize('update', $dossier);

        $etudiant = $dossier->etudiant;

        $document = $this->documents->stocker(
            $etudiant,
            $request->input('annee_universitaire_id')
                ? AnneeUniversitaire::find($request->input('annee_universitaire_id'))
                : null,
            $request->file('document'),
            $request->validated('type_document'),
            (int) $request->user()->id,
        );

        $this->audit->tracer(
            $etudiant,
            HistoriqueAcademique::ACTION_UPLOAD_DOCUMENT,
            'document',
            $document->id,
            null,
            ['nom' => $document->nom_original, 'type' => $document->type_document],
        );

        return redirect()->route('admin.archives.show', $dossier)
            ->with('success', "Document « {$document->nom_original} » archivé en toute sécurité.");
    }

    /**
     * Téléchargement contrôlé : autorisation + existence + audit.
     */
    public function download(DocumentAcademique $document)
    {
        Gate::authorize('download', $document);

        $this->audit->tracer(
            $document->etudiant,
            HistoriqueAcademique::ACTION_TELECHARGEMENT_DOCUMENT,
            'document',
            $document->id,
            null,
            ['nom' => $document->nom_original],
        );

        return $this->documents->telecharger($document);
    }

    public function destroy(DocumentAcademique $document): RedirectResponse
    {
        Gate::authorize('delete', $document);

        $etudiant = $document->etudiant;
        $dossier = $etudiant->dossierAcademique;
        $nom = $document->nom_original;

        $this->audit->tracer(
            $etudiant,
            HistoriqueAcademique::ACTION_SUPPRESSION_DOCUMENT,
            'document',
            $document->id,
            ['nom' => $nom],
        );

        $this->documents->supprimer($document);

        return redirect()->route('admin.archives.show', $dossier)
            ->with('success', "Document « {$nom} » supprimé définitivement.");
    }
}
