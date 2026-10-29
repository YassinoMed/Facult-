<?php

namespace App\Modules\StudentDossiers\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\StudentDossiers\Models\GeneratedDocument;
use App\Modules\StudentDossiers\Models\StudentDocument;
use App\Modules\StudentDossiers\Models\StudentDocumentVersion;
use App\Modules\StudentDossiers\Models\StudentDossier;
use App\Modules\StudentDossiers\Requests\RemplacerMonDocumentRequest;
use App\Modules\StudentDossiers\Requests\TeleverserMonDocumentRequest;
use App\Modules\StudentDossiers\Services\Pdf\PdfDocumentService;
use App\Modules\StudentDossiers\Services\ReutilisationDocumentsService;
use App\Modules\StudentDossiers\Services\StudentDocumentService;
use App\Modules\StudentDossiers\Services\StudentDossierService;
use App\Services\AuditService;
use App\Services\DocumentsRequisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Espace étudiant (§12) : SON dossier uniquement (session, anti-IDOR).
 * Consultation, upload, remplacement, téléchargement — aucune
 * modification des données sensibles administrées.
 */
class MonDossierController extends Controller
{
    public function __construct(
        private readonly StudentDossierService $dossiers,
        private readonly StudentDocumentService $documents,
        private readonly PdfDocumentService $pdfs,
        private readonly AuditService $audit,
        private readonly DocumentsRequisService $documentsRequis,
        private readonly ReutilisationDocumentsService $reutilisation,
    ) {}

    public function show(Request $request): View
    {
        $etudiant = Etudiant::with('faculte')
            ->findOrFail($request->session()->get('etudiant_auth_id'));

        // Provisionnement idempotent : l'upload exige un dossier.
        $dossier = $this->dossiers->obtenirOuCreer($etudiant);
        $dossier->load(['documents' => fn ($q) => $q->latest()]);

        // Checklist documentaire (LOOP 4) : matrice officielle
        // (`config/inscriptions.php`, vide = rien imposé), repli sur la
        // liste globale — `type_inscription` du wizard public n'est pas
        // persisté sur le dossier, on n'invente pas de type. Calculée
        // côté serveur sur les états GED réels, jamais depuis le
        // navigateur.
        $checklist = $this->documentsRequis->verifier($etudiant, null);

        // LOOP-UNIREGISTRE-DOCUMENTS-REUTILISABLES-01 : état de la dernière
        // inscription ouverte (lecture seule). Les pièces validées du
        // dossier permanent sont réutilisées ; seules les pièces réellement
        // manquantes/refusées/expirées/non conformes sont demandées.
        $inscriptionCourante = $this->reutilisation->derniereInscriptionActive($etudiant);
        $reutilisation = $inscriptionCourante !== null
            ? $this->reutilisation->etatInscription($inscriptionCourante)
            : null;

        return view('modules.student-dossiers.mon-dossier', [
            'etudiant' => $etudiant,
            'dossier' => $dossier,
            'pdf' => $this->pdfs->dernierPdf($dossier),
            'checklist' => $checklist,
            'inscriptionCourante' => $inscriptionCourante,
            'reutilisation' => $reutilisation,
        ]);
    }

    public function store(TeleverserMonDocumentRequest $request): RedirectResponse|JsonResponse
    {
        $dossier = $this->dossierConnecte($request);
        $donnees = $request->validated();

        try {
            // Décision définitive (LOOP 11) : aucune modification silencieuse
            // après REJET — la modification passe par la scolarité
            // (réouverture officielle). Un dossier « valide » reste
            // dépositaire (preuves postérieures autorisées : conventions,
            // missions…) — comportement historique préservé.
            if (($dossier->etudiant->statut ?? null) === 'rejete') {
                throw new \RuntimeException('Le dossier a fait l’objet d’une décision définitive. Contactez la scolarité pour une démarche officielle.');
            }

            $document = $this->documents->upload($dossier, $donnees, $request->file('document'), null);
        } catch (\RuntimeException $e) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'errors' => ['document' => [$e->getMessage()]],
                ], 422);
            }

            return redirect()->back()->withErrors(['document' => $e->getMessage()])->withInput();
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Document transmis (en attente de validation).',
                'document' => [
                    'id' => $document->id,
                    'title' => $document->title,
                    'category' => $document->category,
                    'document_type' => $document->document_type,
                    'status' => $document->status,
                    'version' => 1,
                    'original_filename' => $document->original_filename,
                ],
            ]);
        }

        return redirect()->route('etudiant.dossier.show')
            ->with('success', 'Document transmis (en attente de validation).');
    }

    public function replace(RemplacerMonDocumentRequest $request, StudentDocument $document): RedirectResponse|JsonResponse
    {
        $dossier = $this->dossierConnecte($request);

        abort_if($document->student_dossier_id !== $dossier->id, 404);

        try {
            // Décision définitive (LOOP 11) : idem store — pas de
            // remplacement silencieux après décision définitive (même règle
            // que store : rejet bloqué, dossier validé encore dépositaire).
            if (($dossier->etudiant->statut ?? null) === 'rejete') {
                throw new \RuntimeException('Le dossier a fait l’objet d’une décision définitive. Contactez la scolarité pour une démarche officielle.');
            }

            $docMaj = $this->documents->replace($document, $request->file('document'), null);
        } catch (\RuntimeException $e) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'errors' => ['document' => [$e->getMessage()]],
                ], 422);
            }

            return redirect()->back()->withErrors(['document' => $e->getMessage()])->withInput();
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Document remplacé (en attente de validation).',
                'document' => [
                    'id' => $docMaj->id,
                    'title' => $docMaj->title,
                    'category' => $docMaj->category,
                    'document_type' => $docMaj->document_type,
                    'status' => $docMaj->status,
                    'version' => (int) $docMaj->versions()->max('version'),
                    'original_filename' => $docMaj->original_filename,
                ],
            ]);
        }

        return redirect()->route('etudiant.dossier.show')
            ->with('success', 'Document remplacé (en attente de validation).');
    }

    public function preview(Request $request, StudentDocument $document): StreamedResponse
    {
        $dossier = $this->dossierConnecte($request);

        abort_if($document->student_dossier_id !== $dossier->id, 404);

        $this->audit->tracer(
            $dossier->etudiant,
            'student_document.previewed',
            'student_document',
            $document->id,
            null,
            ['par' => 'etudiant'],
        );

        return $this->documents->reponseApercu($document);
    }

    public function download(Request $request, StudentDocument $document): StreamedResponse
    {
        $dossier = $this->dossierConnecte($request);

        abort_if($document->student_dossier_id !== $dossier->id, 404);

        $this->audit->tracer(
            $dossier->etudiant,
            'student_document.downloaded',
            'student_document',
            $document->id,
            null,
            ['par' => 'etudiant'],
        );

        return $this->documents->reponseTelechargement($document);
    }

    public function downloadVersion(
        Request $request,
        StudentDocument $document,
        StudentDocumentVersion $version,
    ): StreamedResponse {
        $dossier = $this->dossierConnecte($request);

        abort_if($document->student_dossier_id !== $dossier->id, 404);
        abort_if($version->student_document_id !== $document->id, 404);

        $this->audit->tracer(
            $dossier->etudiant,
            'student_document.downloaded',
            'student_document',
            $document->id,
            null,
            ['par' => 'etudiant', 'version' => $version->version],
        );

        return $this->documents->reponseTelechargement($document, $version);
    }

    private function dossierConnecte(Request $request): StudentDossier
    {
        $etudiant = Etudiant::findOrFail($request->session()->get('etudiant_auth_id'));

        return $this->dossiers->obtenirOuCreer($etudiant);
    }

    /**
     * Documents officiels de l'étudiant connecté (LOOP 4 §38) : relevés
     * et attestations émis (`valide`), jamais ceux d'un autre dossier
     * (404 par comparaison d'id, session seule faisant foi).
     */
    public function officiels(Request $request): View
    {
        $dossier = $this->dossierConnecte($request);

        $query = GeneratedDocument::where('student_dossier_id', $dossier->id)
            ->where('statut', 'valide')
            ->latest('id');

        $etudiant = $dossier->etudiant;
        if ($etudiant) {
            $publiees = DecisionDeliberation::inscriptionsPublieesPour($etudiant->id);
            if (empty($publiees)) {
                $query->whereNotIn('type', ['releve_notes', 'releve_semestriel', 'releve_cursus']);
            }
        }

        $documents = $query->paginate(15);

        return view('modules.student-dossiers.mon-dossier-officiels', [
            'documents' => $documents,
        ]);
    }

    public function telechargerOfficiel(Request $request, GeneratedDocument $document): StreamedResponse
    {
        $dossier = $this->dossierConnecte($request);

        abort_if((int) $document->student_dossier_id !== (int) $dossier->id, 404);
        abort_if($document->statut !== 'valide', 410, 'Document non disponible.');
        abort_unless(Storage::disk('private')->exists($document->storage_path), 404);

        if (in_array($document->type, ['releve_notes', 'releve_semestriel', 'releve_cursus'], true)) {
            $etudiant = $dossier->etudiant;
            if ($etudiant) {
                $publiees = DecisionDeliberation::inscriptionsPublieesPour($etudiant->id);
                $anneeId = $document->snapshot_donnees['annee_universitaire_id'] ?? null;
                $inscriptionsConcernees = InscriptionAcademique::where('etudiant_id', $etudiant->id)
                    ->when($anneeId, fn ($q) => $q->where('annee_universitaire_id', $anneeId))
                    ->pluck('id')
                    ->all();

                $estPublie = ! empty(array_intersect($inscriptionsConcernees, $publiees));
                abort_unless($estPublie, 403, 'Le relevé de notes n’est pas encore publié.');
            }
        }

        $this->audit->tracer(
            $dossier->etudiant,
            'student_document.downloaded',
            'generated_document',
            $document->id,
            null,
            ['par' => 'etudiant', 'reference' => $document->reference],
        );

        return Storage::disk('private')->download($document->storage_path, ($document->reference ?? 'document').'.pdf');
    }
}
