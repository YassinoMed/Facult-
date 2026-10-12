<?php

namespace App\Http\Controllers\Etudiant;

use App\Http\Controllers\Controller;
use App\Models\DocumentAcademique;
use App\Models\DossierAcademique;
use App\Models\Etudiant;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\StudentDossiers\Contracts\PdfGeneratorInterface;
use App\Services\AcademicCalculationService;
use App\Services\AuditService;
use App\Services\DocumentService;
use App\Services\ParcoursEtudiantService;
use App\Services\PeriodeInscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Consultation étudiante de l'archive académique — STRICTEMENT limitée :
 * - l'étudiant est TOUJOURS résolu depuis la session (jamais depuis un ID
 *   fourni par le client : aucune consultation croisée possible) ;
 * - aucun droit de modification de notes/décisions/documents ;
 * - sections dédiées (§3) : inscriptions, notes, documents, historique —
 *   toutes en lecture seule sur SON propre dossier.
 */
class ArchiveController extends Controller
{
    public function __construct(
        private readonly AcademicCalculationService $calculs,
        private readonly AuditService $audit,
        private readonly DocumentService $documents,
    ) {}

    public function show(Request $request): View
    {
        $etudiant = $this->etudiantConnecte($request);

        $dossier = DossierAcademique::where('etudiant_id', $etudiant->id)->first();

        abort_if($dossier === null, 404, 'Aucun dossier académique ne vous est rattaché.');

        $inscriptions = InscriptionAcademique::where('etudiant_id', $etudiant->id)
            ->with([
                'anneeUniversitaire',
                'semestres.notes.matiere' => fn ($q) => $q->orderBy('code'),
            ])
            ->orderByDesc('annee_universitaire_id')
            ->get();

        $synthese = $this->calculs->synthese($inscriptions);

        $documents = $etudiant->documentsAcademiques()
            ->with('anneeUniversitaire')
            ->latest()
            ->get();

        return view('etudiant.archives', [
            'etudiant' => $etudiant,
            'dossier' => $dossier,
            'inscriptions' => $inscriptions,
            'synthese' => $synthese,
            'documents' => $documents,
        ]);
    }

    /**
     * §3 — Ses inscriptions académiques (une par année universitaire,
     * jamais un nouvel étudiant par année : §12-13).
     */
    public function inscriptions(Request $request): View
    {
        $etudiant = $this->etudiantConnecte($request);

        $inscriptions = InscriptionAcademique::where('etudiant_id', $etudiant->id)
            ->with(['anneeUniversitaire', 'semestres'])
            ->orderByDesc('annee_universitaire_id')
            ->get();

        return view('etudiant.inscriptions', [
            'etudiant' => $etudiant,
            'inscriptions' => $inscriptions,
        ]);
    }

    /**
     * §3 — Ses notes uniquement (lecture seule, jamais modifiables).
     * Phase 14 §14.7 : seules les inscriptions à décision publiée
     * sont visibles — jamais de notes avant publication du jury.
     */
    public function notes(Request $request): View
    {
        $etudiant = $this->etudiantConnecte($request);

        $publiees = DecisionDeliberation::where('etudiant_id', $etudiant->id)
            ->where('publie', true)
            ->pluck('inscription_academique_id')->all();

        $inscriptions = InscriptionAcademique::where('etudiant_id', $etudiant->id)
            ->whereIn('id', $publiees)
            ->with([
                'anneeUniversitaire',
                'semestres.notes.matiere' => fn ($q) => $q->orderBy('code'),
            ])
            ->orderByDesc('annee_universitaire_id')
            ->get();

        return view('etudiant.notes', [
            'etudiant' => $etudiant,
            'inscriptions' => $inscriptions,
        ]);
    }

    /**
     * §3 — SES documents autorisés (métadonnées + téléchargement contrôlé).
     */
    public function documents(Request $request): View
    {
        $etudiant = $this->etudiantConnecte($request);

        $documents = $etudiant->documentsAcademiques()
            ->with('anneeUniversitaire')
            ->latest()
            ->get();

        return view('etudiant.documents', [
            'etudiant' => $etudiant,
            'documents' => $documents,
        ]);
    }

    /**
     * §3 — Son historique académique (journal en lecture seule).
     */
    public function historique(Request $request): View
    {
        $etudiant = $this->etudiantConnecte($request);

        $historique = HistoriqueAcademique::where('etudiant_id', $etudiant->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return view('etudiant.historique', [
            'etudiant' => $etudiant,
            'historique' => $historique,
        ]);
    }

    /**
     * Téléchargement de SES documents uniquement (vérification d'appartenance).
     */
    public function download(Request $request, DocumentAcademique $document)
    {
        $etudiant = $this->etudiantConnecte($request);

        // Anti-IDOR : appartenance stricte au dossier de l'étudiant connecté
        abort_if($document->etudiant_id !== $etudiant->id, 404);

        $this->audit->tracer(
            $etudiant,
            HistoriqueAcademique::ACTION_TELECHARGEMENT_DOCUMENT,
            'document',
            $document->id,
            null,
            ['par' => 'etudiant'],
        );

        return $this->documents->telecharger($document);
    }

    /**
     * Parcours LMD (LOOP 13) : même service que le back-office,
     * session-scopé — l'étudiant ne voit que SON parcours.
     */
    public function parcours(Request $request, ParcoursEtudiantService $parcours): View
    {
        return view('etudiant.parcours', [
            'etudiant' => $this->etudiantConnecte($request),
            'parcours' => $parcours->consulter($this->etudiantConnecte($request)),
        ]);
    }

    /**
     * PDF du parcours (LOOP C4) : document INFORMATIF généré à la
     * demande via le moteur officiel, sans stockage, sans CIN — ni
     * relevé de notes, ni attestation (mention imprimée). Échec
     * intercepté, jamais de 500.
     */
    public function parcoursPdf(Request $request, ParcoursEtudiantService $parcours, PdfGeneratorInterface $pdf): Response
    {
        $etudiant = $this->etudiantConnecte($request);

        try {
            $html = view('etudiant.parcours-pdf', [
                'etudiant' => $etudiant,
                'parcours' => $parcours->consulter($etudiant),
                'dateEdition' => now()->setTimezone(PeriodeInscriptionService::fuseau()),
            ])->render();

            $contenu = $pdf->generer($html, ['paper' => 'A4', 'orientation' => 'portrait']);
        } catch (\Throwable $e) {
            Log::error('parcours.generation_echec', [
                'etudiant_id' => $etudiant->id,
                'exception' => $e::class,
            ]);

            return redirect()->route('etudiant.parcours')
                ->withErrors(['parcours' => 'Impossible de générer le parcours pour le moment — réessayez plus tard.']);
        }

        return response($contenu, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="parcours-'.$etudiant->num_inscription.'.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-cache, no-store, must-revalidate',
        ]);
    }

    /**
     * L'étudiant connecté est TOUJOURS celui de la session.
     */
    private function etudiantConnecte(Request $request): Etudiant
    {
        return Etudiant::with('faculte')
            ->findOrFail($request->session()->get('etudiant_auth_id'));
    }
}
