<?php

namespace App\Modules\StudentDossiers\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Modules\StudentDossiers\Events\StudentDossierPdfDownloaded;
use App\Modules\StudentDossiers\Events\StudentDossierPdfPreviewed;
use App\Modules\StudentDossiers\Events\StudentDossierPdfPrinted;
use App\Modules\StudentDossiers\Models\GeneratedDocument;
use App\Modules\StudentDossiers\Models\StudentDossier;
use App\Modules\StudentDossiers\Requests\GenerateStudentDossierPdfRequest;
use App\Modules\StudentDossiers\Services\DocumentStorageService;
use App\Modules\StudentDossiers\Services\Pdf\StudentDossierPdfService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * PDF du dossier (§10-13) : génération, prévisualisation, flux inline,
 * téléchargement, intention d'impression. Contrôleur fin — le MIME
 * servi est celui persisté, le chemin interne jamais exposé.
 */
class StudentDossierPdfController extends Controller
{
    public function __construct(
        private readonly StudentDossierPdfService $pdfs,
        private readonly DocumentStorageService $stockage,
    ) {}

    // ── Back-office ──────────────────────────────────────────────

    public function generer(GenerateStudentDossierPdfRequest $request, StudentDossier $dossier): RedirectResponse
    {
        $this->verifierPerimetre($request, $dossier);
        Gate::authorize('generatePdf', $dossier);

        // Régénération forcée (nouvelle version) ou idempotente.
        if ($request->boolean('force')) {
            $this->pdfs->genererMaintenant($dossier, $request->user()->id);

            return redirect()->route('admin.student-dossiers.pdf.preview', $dossier)
                ->with('success', 'Document PDF régénéré.');
        }

        $resultat = $this->pdfs->demander($dossier, $request->user()->id);

        return redirect()->route('admin.student-dossiers.pdf.preview', $dossier)->with(
            $resultat['statut'] === 'pret' ? 'success' : 'info',
            $resultat['statut'] === 'pret'
                ? 'Document PDF généré.'
                : 'Dossier volumineux : génération en cours, revenez dans un instant.',
        );
    }

    public function preview(Request $request, StudentDossier $dossier): View
    {
        $document = $this->documentPret($request, $dossier, 'viewPdf');

        StudentDossierPdfPreviewed::dispatch($document);

        return view('modules.student-dossiers.pdf-preview', [
            'dossier' => $dossier,
            'document' => $document,
            'urlFichier' => route('admin.student-dossiers.pdf.fichier', $dossier),
            'urlTelechargement' => route('admin.student-dossiers.pdf.download', $dossier),
            'urlImpression' => route('admin.student-dossiers.pdf.print', $dossier),
        ]);
    }

    public function fichier(Request $request, StudentDossier $dossier): StreamedResponse
    {
        $document = $this->documentPret($request, $dossier, 'viewPdf');

        return $this->reponseFichier($document, false);
    }

    public function download(Request $request, StudentDossier $dossier): StreamedResponse
    {
        $document = $this->documentPret($request, $dossier, 'downloadPdf');

        StudentDossierPdfDownloaded::dispatch($document);

        return $this->reponseFichier($document, true);
    }

    public function print(Request $request, StudentDossier $dossier): RedirectResponse|Response
    {
        $document = $this->documentPret($request, $dossier, 'printPdf');

        StudentDossierPdfPrinted::dispatch($document);

        // Appel AJAX du bouton « Imprimer » (page d'aperçu) : accusé
        // léger, l'impression elle-même a lieu dans l'iframe.
        if ($request->ajax()) {
            return response()->noContent();
        }

        return redirect()->route('admin.student-dossiers.pdf.fichier', $dossier);
    }

    // ── Espace étudiant (dossier propre, session-scopé) ──────────

    public function genererEtu(Request $request): RedirectResponse
    {
        $dossier = $this->dossierConnecte($request);

        $resultat = $this->pdfs->demander($dossier, null);

        return redirect()->route('etudiant.dossier.pdf.preview')->with(
            $resultat['statut'] === 'pret' ? 'success' : 'info',
            $resultat['statut'] === 'pret'
                ? 'Document PDF généré.'
                : 'Génération en cours, revenez dans un instant.',
        );
    }

    public function apercu(Request $request): View
    {
        $dossier = $this->dossierConnecte($request);
        $document = $this->pdfs->demander($dossier, null)['document'] ?? null;

        abort_if($document === null, 409, 'Génération en cours, revenez dans un instant.');

        StudentDossierPdfPreviewed::dispatch($document);

        return view('modules.student-dossiers.pdf-preview', [
            'dossier' => $dossier,
            'document' => $document,
            'urlFichier' => route('etudiant.dossier.pdf.fichier'),
            'urlTelechargement' => route('etudiant.dossier.pdf.download'),
            'urlImpression' => route('etudiant.dossier.pdf.print'),
        ]);
    }

    public function fichierEtu(Request $request): StreamedResponse
    {
        return $this->reponseFichier($this->documentEtuPret($request), false);
    }

    public function telecharger(Request $request): StreamedResponse
    {
        $document = $this->documentEtuPret($request);

        StudentDossierPdfDownloaded::dispatch($document);

        return $this->reponseFichier($document, true);
    }

    public function imprimer(Request $request): RedirectResponse|Response
    {
        $document = $this->documentEtuPret($request);

        StudentDossierPdfPrinted::dispatch($document);

        if ($request->ajax()) {
            return response()->noContent();
        }

        return redirect()->route('etudiant.dossier.pdf.fichier');
    }

    // ── Privé ────────────────────────────────────────────────────

    private function verifierPerimetre(Request $request, StudentDossier $dossier): void
    {
        $dossier->loadMissing('etudiant');

        abort_unless($request->user()->peutAccederFaculte((int) $dossier->etudiant->faculte_id), 404);
    }

    /**
     * PDF frais ou redirection d'attente (dossier volumineux en job).
     */
    private function documentPret(Request $request, StudentDossier $dossier, string $capacite): GeneratedDocument
    {
        $this->verifierPerimetre($request, $dossier);
        Gate::authorize($capacite, $dossier);

        $resultat = $this->pdfs->demander($dossier, $request->user()->id);

        if (($resultat['statut'] ?? null) !== 'pret' || ! isset($resultat['document'])) {
            abort(redirect()->route('admin.student-dossiers.pdf.preview', $dossier)->with(
                'info',
                'Génération en cours, revenez dans un instant.'
            ));
        }

        return $resultat['document'];
    }

    private function dossierConnecte(Request $request): StudentDossier
    {
        $etudiant = Etudiant::findOrFail($request->session()->get('etudiant_auth_id'));

        return StudentDossier::where('etudiant_id', $etudiant->id)->firstOrFail();
    }

    private function documentEtuPret(Request $request): GeneratedDocument
    {
        $dossier = $this->dossierConnecte($request);

        $resultat = $this->pdfs->demander($dossier, null);

        if (($resultat['statut'] ?? null) !== 'pret' || ! isset($resultat['document'])) {
            abort(409, 'Génération en cours, revenez dans un instant.');
        }

        return $resultat['document'];
    }

    private function reponseFichier(GeneratedDocument $document, bool $telechargement): StreamedResponse
    {
        $reponse = response()->stream(function () use ($document) {
            $flux = $this->stockage->lireFlux($document->storage_path);
            fpassthru($flux);
            fclose($flux);
        }, 200, [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        // Disposition explicite : inline (prévisualisation) ou attachment
        // (téléchargement), nom sécurisé + repli ASCII.
        $reponse->headers->set('Content-Disposition', $reponse->headers->makeDisposition(
            $telechargement ? 'attachment' : 'inline',
            $this->stockage->nomTelechargementSecurise($document->reference.'.pdf'),
            'dossier.pdf',
        ));

        return $reponse;
    }
}
