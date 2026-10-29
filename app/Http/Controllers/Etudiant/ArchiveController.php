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

        // D-01 : seuls les résultats couverts par une décision publiée
        // alimentent la synthèse et sont rendus à la vue.
        $resultatsPublies = DecisionDeliberation::inscriptionsPublieesPour($etudiant->id);

        $inscriptionsPubliees = $inscriptions
            ->filter(fn (InscriptionAcademique $inscription) => in_array((int) $inscription->id, $resultatsPublies, true))
            ->values();

        $synthese = $this->calculs->synthese($inscriptionsPubliees);

        $inscriptions = $inscriptions
            ->each(fn (InscriptionAcademique $inscription) => $this->masquerResultatsNonPublies($inscription, $resultatsPublies));

        $documents = $etudiant->documentsAcademiques()
            ->with('anneeUniversitaire')
            ->latest()
            ->get();

        return view('etudiant.archives', [
            'etudiant' => $etudiant,
            'dossier' => $dossier,
            'inscriptions' => $inscriptions,
            'resultatsPublies' => $resultatsPublies,
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
            ->with(['anneeUniversitaire', 'semestres', 'dossierPaiement'])
            ->orderByDesc('annee_universitaire_id')
            ->get();

        // D-01 : les métadonnées d'inscription restent visibles, mais
        // aucun résultat (moyenne, crédits, décision, semestres) n'est
        // rendu tant que la délibération n'est pas publiée.
        $resultatsPublies = DecisionDeliberation::inscriptionsPublieesPour($etudiant->id);

        $inscriptions = $inscriptions
            ->each(fn (InscriptionAcademique $inscription) => $this->masquerResultatsNonPublies($inscription, $resultatsPublies, false));

        return view('etudiant.inscriptions', [
            'etudiant' => $etudiant,
            'inscriptions' => $inscriptions,
            'resultatsPublies' => $resultatsPublies,
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

        $publiees = DecisionDeliberation::inscriptionsPublieesPour($etudiant->id);

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

        // Lot A (§9) : les relevés étudiants ne peuvent être téléchargés que si les résultats sont publiés
        if (in_array($document->type_document, ['releve_notes', 'releve_semestriel', 'releve_cursus'], true)) {
            $publiees = DecisionDeliberation::inscriptionsPublieesPour($etudiant->id);
            $inscriptionsConcernees = InscriptionAcademique::where('etudiant_id', $etudiant->id)
                ->when($document->annee_universitaire_id, fn ($q) => $q->where('annee_universitaire_id', $document->annee_universitaire_id))
                ->pluck('id')
                ->all();

            $estPublie = ! empty(array_intersect($inscriptionsConcernees, $publiees));
            abort_unless($estPublie, 403, 'Le relevé de notes n’est pas encore publié.');
        }

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
     * D-01 : une inscription sans décision de délibération publiée ne
     * doit exposer aucun résultat. Les colonnes persistées de
     * l'inscription (moyenne_annuelle, crédits, decision) sont
     * recalculées dès la saisie d'une note — elles ne valent donc pas
     * publication. Les métadonnées (niveau, statut, année, paiement)
     * ne sont pas des résultats et restent visibles.
     *
     * @param  array<int, int>  $resultatsPublies
     */
    private function masquerResultatsNonPublies(InscriptionAcademique $inscription, array $resultatsPublies, bool $masquerSemestres = true): InscriptionAcademique
    {
        if (in_array((int) $inscription->id, $resultatsPublies, true)) {
            return $inscription;
        }

        $inscription->moyenne_annuelle = null;
        $inscription->credits_obtenus = 0;
        $inscription->credits_total = 0;
        $inscription->decision = null;

        if ($masquerSemestres) {
            $inscription->setRelation('semestres', collect());
        }

        return $inscription;
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
