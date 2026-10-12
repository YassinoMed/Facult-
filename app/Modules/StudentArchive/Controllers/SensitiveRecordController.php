<?php

namespace App\Modules\StudentArchive\Controllers;

use App\Http\Controllers\Controller;
use App\Models\DocumentAcademique;
use App\Models\Etudiant;
use App\Models\HistoriqueAcademique;
use App\Modules\OnlineRequests\Models\OnlineRequestDocument;
use App\Modules\StudentArchive\Enums\TypeDossierSensible;
use App\Modules\StudentArchive\Models\StudentSensitiveRecord;
use App\Modules\StudentArchive\Policies\SensitiveRecordPolicy;
use App\Modules\StudentArchive\Services\StudentArchiveService;
use App\Modules\StudentDossiers\Models\StudentDocument;
use App\Modules\StudentDossiers\Services\StudentDocumentService;
use App\Services\AuditService;
use App\Services\DocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dossiers sensibles (§13-14, §38) : CRUD minimal + téléchargement GED.
 * Scope faculté AVANT permission (404), audit systématique (§23),
 * jamais de contenu sensible dans les messages (§21-22).
 */
class SensitiveRecordController extends Controller
{
    public function __construct(
        private readonly StudentArchiveService $archives,
        private readonly SensitiveRecordPolicy $policy,
        private readonly AuditService $audit,
    ) {}

    private function dossierDansPerimetre(Request $request, Etudiant $etudiant, StudentSensitiveRecord $dossier): void
    {
        abort_unless((int) $dossier->etudiant_id === (int) $etudiant->id, 404);
        abort_unless($request->user()->peutAccederFaculte((int) $etudiant->faculte_id), 404);
    }

    public function store(Request $request, Etudiant $etudiant): RedirectResponse
    {
        abort_unless($request->user()->peutAccederFaculte((int) $etudiant->faculte_id), 404);
        abort_unless($request->user()->can('student_archive.sensitive.manage'), 403);

        $valide = $request->validate([
            'type' => ['required', Rule::in(array_column(TypeDossierSensible::cases(), 'value'))],
            // Titre NEUTRE exigé (§9) : libellé administratif, jamais de diagnostic.
            'titre' => ['required', 'string', 'min:3', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'niveau' => ['nullable', 'in:restreint,confidentiel'],
            'date_debut' => ['nullable', 'date'],
            'date_fin' => ['nullable', 'date', 'after_or_equal:date_debut'],
        ]);

        $dossier = StudentSensitiveRecord::create($valide + [
            'etudiant_id' => $etudiant->id,
            'statut' => 'ouvert',
            'created_by' => $request->user()->id,
        ]);

        $this->audit->tracer($etudiant, HistoriqueAcademique::ACTION_SENSITIVE_CREATE, 'sensible', $dossier->id);

        return redirect()->route('admin.etudiants.archive', [$etudiant, 'onglet' => 'confidentiel'])
            ->with('success', 'Dossier sensible enregistré.');
    }

    public function update(Request $request, Etudiant $etudiant, StudentSensitiveRecord $dossier): RedirectResponse
    {
        $this->dossierDansPerimetre($request, $etudiant, $dossier);
        abort_unless($this->policy->gerer($request->user(), $dossier), 403);

        $valide = $request->validate([
            'titre' => ['sometimes', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'statut' => ['sometimes', 'in:ouvert,en_cours,cloture,archive'],
            'date_fin' => ['nullable', 'date'],
            'retention_policy' => ['nullable', 'string', 'max:100'],
            'retention_until' => ['nullable', 'date'],
        ]);

        $dossier->update($valide + ['updated_by' => $request->user()->id]);

        $this->audit->tracer($etudiant, HistoriqueAcademique::ACTION_SENSITIVE_UPDATE, 'sensible', $dossier->id);

        return redirect()->route('admin.etudiants.archive', [$etudiant, 'onglet' => 'confidentiel'])
            ->with('success', 'Dossier sensible mis à jour.');
    }

    public function download(Request $request, Etudiant $etudiant, StudentSensitiveRecord $dossier): Response
    {
        $this->dossierDansPerimetre($request, $etudiant, $dossier);

        // Non communicable (aucune pièce GED) → 404 avant tout contrôle
        // de permission (ne pas révéler le motif du refus).
        abort_if($dossier->document_source === null || $dossier->document_id === null, 404);

        abort_unless($this->policy->telecharger($request->user(), $dossier), 403);

        $reponse = match ($dossier->document_source) {
            'ged' => $this->telechargerGed($dossier),
            'dossier' => $this->telechargerDossier($dossier, $request),
            'demande' => $this->telechargerDemande($dossier, $request),
            default => abort(404, 'Pièce non communicable en ligne.'),
        };

        $action = match ($dossier->type) {
            'medical' => HistoriqueAcademique::ACTION_MEDICAL_DOWNLOAD,
            'disciplinaire' => HistoriqueAcademique::ACTION_DISCIPLINARY_DOWNLOAD,
            default => HistoriqueAcademique::ACTION_SENSITIVE_DOWNLOAD,
        };
        $this->audit->tracer($etudiant, $action, 'sensible', $dossier->id);

        return $reponse;
    }

    private function telechargerGed(StudentSensitiveRecord $dossier): Response
    {
        $document = DocumentAcademique::findOrFail($dossier->document_id);
        abort_unless((int) $document->etudiant_id === (int) $dossier->etudiant_id, 404);
        abort_unless(auth()->user()->can('documents.download'), 403);

        return app(DocumentService::class)->telecharger($document);
    }

    private function telechargerDossier(StudentSensitiveRecord $dossier, Request $request): Response
    {
        abort_unless($request->user()->can('student_documents.download'), 403);

        $document = StudentDocument::findOrFail($dossier->document_id);
        // Liaison étudiant obligatoire (audit sécurité 04/10/2026) —
        // comme telechargerGed/telechargerDemande : un dossier sensible ne
        // sert jamais le document d'un AUTRE étudiant (404 silencieux).
        abort_unless((int) $document->dossier?->etudiant_id === (int) $dossier->etudiant_id, 404);

        return app(StudentDocumentService::class)
            ->reponseTelechargement($document);
    }

    private function telechargerDemande(StudentSensitiveRecord $dossier, Request $request): Response
    {
        abort_unless($request->user()->can('demandes.documents.download'), 403);

        $document = OnlineRequestDocument::findOrFail($dossier->document_id);
        abort_unless((int) $document->demande->etudiant_id === (int) $dossier->etudiant_id, 404);

        return Storage::disk('private')->download(
            $document->storage_path, $document->reference.'.pdf'
        );
    }
}
