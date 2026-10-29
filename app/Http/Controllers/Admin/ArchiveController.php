<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDossierRequest;
use App\Http\Requests\UpdateDossierRequest;
use App\Models\AnneeUniversitaire;
use App\Models\DossierAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\Matiere;
use App\Modules\Referentiel\Models\Programme;
use App\Services\AcademicCalculationService;
use App\Services\ArchiveService;
use App\Services\AuditService;
use App\Services\DocumentsRequisService;
use App\Services\FicheEtudiantService;
use App\Services\Io\CsvSerializer;
use App\Support\Recherche;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ArchiveController extends Controller
{
    public function __construct(
        private readonly AcademicCalculationService $calculs,
        private readonly ArchiveService $archives,
        private readonly AuditService $audit,
        private readonly CsvSerializer $csv,
    ) {}

    /**
     * Registre des dossiers académiques : recherche multicritère + filtres.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', DossierAcademique::class);

        $query = DossierAcademique::query()
            ->join('etudiants', 'etudiants.id', '=', 'archives_etudiants.etudiant_id')
            ->join('facultes', 'facultes.id', '=', 'etudiants.faculte_id')
            ->select('archives_etudiants.*');

        // ── Isolation par faculté (§6) : un admin_faculte ne voit que
        // les dossiers de SA faculté — périmètre imposé côté serveur.
        $faculteRestreinteId = $request->user()->faculteRestreinteId();
        if ($faculteRestreinteId !== null) {
            $query->where('etudiants.faculte_id', $faculteRestreinteId);
        }

        // Recherche : CIN, nom, prénom, email, n° inscription, matricule.
        // Les jokers LIKE saisis sont neutralisés (audit §P3-22).
        if ($recherche = trim((string) $request->string('search'))) {
            $motif = Recherche::contient($recherche);
            $query->where(function ($q) use ($motif) {
                $q->where('etudiants.nom', 'like', $motif)
                    ->orWhere('etudiants.prenom', 'like', $motif)
                    ->orWhere('etudiants.cin', 'like', $motif)
                    ->orWhere('etudiants.email', 'like', $motif)
                    ->orWhere('etudiants.num_inscription', 'like', $motif)
                    ->orWhere('archives_etudiants.matricule', 'like', $motif);
            });
        }

        // Filtre par faculté : borné au périmètre — un admin_faculte ne
        // peut pas contourner son scope via le paramètre faculte_id.
        if ($faculteRestreinteId !== null) {
            // déjà imposé ci-dessus
        } elseif ($faculteId = $request->input('faculte_id')) {
            $query->where('etudiants.faculte_id', $faculteId);
        }

        if ($anneeId = $request->input('annee_universitaire_id')) {
            $query->whereExists(function ($q) use ($anneeId) {
                $q->select(\DB::raw(1))
                    ->from('inscriptions_academiques')
                    ->whereColumn('inscriptions_academiques.etudiant_id', 'etudiants.id')
                    ->where('inscriptions_academiques.annee_universitaire_id', $anneeId);
            });
        }

        if ($niveau = $request->input('niveau')) {
            $query->whereExists(function ($q) use ($niveau) {
                $q->select(\DB::raw(1))
                    ->from('inscriptions_academiques')
                    ->whereColumn('inscriptions_academiques.etudiant_id', 'etudiants.id')
                    ->where('inscriptions_academiques.niveau', $niveau);
            });
        }

        if ($statut = $request->input('statut')) {
            $query->where('archives_etudiants.statut', $statut);
        }

        if ($programmeId = $request->input('programme_id')) {
            $query->whereExists(function ($q) use ($programmeId) {
                $q->select(\DB::raw(1))
                    ->from('inscriptions_academiques')
                    ->whereColumn('inscriptions_academiques.etudiant_id', 'etudiants.id')
                    ->where('inscriptions_academiques.programme_id', $programmeId);
            });
        }

        // Agrégats sur une projection SANS les colonnes de la liste
        // (LOOP 8 : `select(*)` + agrégat sans GROUP BY = 1140 sur
        // MySQL ONLY_FULL_GROUP_BY — invisible sous SQLite. Note :
        // `cloneWithout()` ne porte pas via l'Eloquent Builder
        // (retourne $this), d'où le reset explicite sur la base).
        $statsQuery = clone $query;
        $statsQuery->getQuery()->columns = null;
        $statsQuery->getQuery()->orders = null;
        $statsRaw = $statsQuery
            ->selectRaw("COUNT(*) as total, SUM(CASE WHEN archives_etudiants.statut = 'actif' THEN 1 ELSE 0 END) as actifs, SUM(CASE WHEN archives_etudiants.statut = 'clos' THEN 1 ELSE 0 END) as clos")
            ->first();

        $stats = [
            'total' => (int) ($statsRaw->total ?? 0),
            'actifs' => (int) ($statsRaw->actifs ?? 0),
            'clos' => (int) ($statsRaw->clos ?? 0),
        ];

        $dossiers = $query->with(['etudiant.faculte'])
            ->withCount('inscriptions')
            ->orderBy('etudiants.nom')
            ->orderBy('archives_etudiants.id')
            ->paginate(12)
            ->withQueryString();

        // Sélecteur de facultés : restreint au périmètre de l'utilisateur
        $facultes = $faculteRestreinteId !== null
            ? Faculte::where('id', $faculteRestreinteId)->orderBy('nom')->get()
            : Faculte::orderBy('nom')->get();
        $annees = AnneeUniversitaire::orderByDesc('libelle')->get();
        $niveaux = ['L1', 'L2', 'L3', 'M1', 'M2', 'D1', 'D2', 'D3'];
        $programmes = Programme::orderBy('code')
            ->when($faculteRestreinteId !== null, fn ($q) => $q->where('faculte_id', $faculteRestreinteId))
            ->get();

        return view('admin.archives.index', compact('dossiers', 'facultes', 'annees', 'niveaux', 'programmes', 'stats'));
    }

    /**
     * Fiche complète du dossier académique d'un étudiant.
     */
    public function show(DossierAcademique $dossier): View
    {
        // La policy lit l'étudiant/faculté : charger une seule fois AVANT
        // l'autorisation évite que la policy puis l'eager-loading de la
        // vue ne rechargent le même étudiant (N+1 de fiche).
        $dossier->loadMissing('etudiant.faculte');

        Gate::authorize('view', $dossier);

        $dossier->loadMissing([
            'etudiant.faculte',
            'inscriptions' => fn ($q) => $q->with([
                'anneeUniversitaire',
                // notes.etudiant : les policies @can en Blade lisent
                // $note->etudiant->faculte_id par ligne (LOOP 3, anti-N+1).
                'semestres.notes.matiere' => fn ($q) => $q->orderBy('code'),
                'semestres.notes.etudiant',
            ])->withCount('pedagogiques')->orderByDesc('annee_universitaire_id'),
        ]);

        $inscriptions = $dossier->inscriptions;
        $synthese = $this->calculs->synthese($inscriptions);

        $documents = $dossier->etudiant->documentsAcademiques()
            ->with(['anneeUniversitaire', 'televersePar'])
            ->latest()
            ->get();

        // Journal d'audit : la consultation du dossier est tracée
        $this->audit->consultation($dossier->etudiant, $dossier->id);

        // Journal d'audit : restreint aux agents habilités à MODIFIER le
        // dossier (l'historique expose acteur, IP et anciennes/nouvelles
        // valeurs — lecture seule ne suffit pas, LOOP 3). Jamais chargé
        // sinon : ni requête, ni divulgation.
        $journal = Gate::allows('update', $dossier)
            ? HistoriqueAcademique::where('etudiant_id', $dossier->etudiant_id)
                ->with('utilisateur')
                ->latest('id')
                ->limit(30)
                ->get()
            : collect();

        $ficheService = app(FicheEtudiantService::class);
        $completude = $ficheService->completude($dossier->etudiant);
        $anomalies = $ficheService->anomalies($dossier->etudiant);
        $piecesDossier = app(DocumentsRequisService::class)->checklistDossier($dossier->etudiant);

        return view('admin.archives.show', [
            'dossier' => $dossier,
            'etudiant' => $dossier->etudiant,
            'inscriptions' => $inscriptions,
            'synthese' => $synthese,
            'documents' => $documents,
            'journal' => $journal,
            'completude' => $completude,
            'anomalies' => $anomalies,
            'piecesDossier' => $piecesDossier,
            'annees' => AnneeUniversitaire::orderByDesc('libelle')->get(),
            'matieres' => Matiere::orderBy('niveau')->orderBy('semestre')->orderBy('code')->get(),
        ]);
    }

    /**
     * Sélection d'un étudiant sans dossier pour création.
     */
    public function create(Request $request): View
    {
        Gate::authorize('create', DossierAcademique::class);

        $etudiantsSansDossier = Etudiant::whereDoesntHave('dossierAcademique')
            ->with('faculte')
            ->when($request->user()->faculteRestreinteId(), fn ($q, $faculteId) => $q->where('faculte_id', $faculteId))
            ->orderBy('nom')
            ->get();

        return view('admin.archives.create', compact('etudiantsSansDossier'));
    }

    public function store(StoreDossierRequest $request): RedirectResponse
    {
        Gate::authorize('create', DossierAcademique::class);

        $etudiant = $request->etudiant();

        // Isolation faculté : même avec archives.create, on ne crée un
        // dossier que pour un étudiant de son périmètre (anti-IDOR §6).
        abort_unless($request->user()->peutAccederFaculte((int) $etudiant->faculte_id), 403);

        $dossier = $this->archives->creerDossier(
            $etudiant,
            $request->validated(),
        );

        return redirect()->route('admin.archives.show', $dossier)
            ->with('success', "Dossier académique créé (matricule {$dossier->matricule}).");
    }

    public function edit(DossierAcademique $dossier): View
    {
        Gate::authorize('update', $dossier);

        return view('admin.archives.edit', [
            'dossier' => $dossier->load('etudiant.faculte'),
        ]);
    }

    public function update(UpdateDossierRequest $request, DossierAcademique $dossier): RedirectResponse
    {
        Gate::authorize('update', $dossier);

        $dossier->update($request->validated());

        $this->audit->tracer(
            $dossier->etudiant,
            HistoriqueAcademique::ACTION_MODIFICATION_DOSSIER,
            'dossier',
            $dossier->id,
            null,
            ['statut' => $dossier->statut, 'observation' => $dossier->observation],
        );

        return redirect()->route('admin.archives.show', $dossier)
            ->with('success', 'Dossier mis à jour avec succès.');
    }

    public function destroy(Request $request, DossierAcademique $dossier): RedirectResponse
    {
        Gate::authorize('delete', $dossier);

        // Motif obligatoire : la suppression est irréversible (LOOP 5).
        $motif = $request->validate(
            ['motif' => 'required|string|min:10|max:1000'],
            ['motif.required' => 'Le motif de suppression est obligatoire.',
                'motif.min' => 'Le motif doit comporter au moins 10 caractères.']
        )['motif'];

        $matricule = $dossier->matricule;
        $etudiant = $dossier->etudiant;

        // Transaction unique : parcours (inscriptions → semestres → notes),
        // documents physiques, audit et dossier disparaissent ensemble —
        // aucun état partiel incohérent en cas d'échec intermédiaire.
        // Re-vérification sous verrou (LOOP 11) : en cas de suppression
        // concurrente, le second flot attend puis constate la
        // disparition (404) au lieu d'écrire un audit en double.
        \Illuminate\Support\Facades\DB::transaction(function () use ($etudiant, $dossier, $matricule, $motif) {
            abort_unless(
                DossierAcademique::whereKey($dossier->id)->lockForUpdate()->exists(),
                404
            );

            foreach ($etudiant->documentsAcademiques()->get() as $document) {
                Storage::disk('local')->delete($document->chemin);
            }
            $etudiant->documentsAcademiques()->delete();
            $etudiant->inscriptionsAcademiques()->delete();

            $this->audit->tracer(
                $etudiant,
                HistoriqueAcademique::ACTION_SUPPRESSION_DOSSIER,
                'dossier',
                $dossier->id,
                ['matricule' => $matricule],
                ['motif' => $motif],
            );

            $dossier->delete();
        });

        return redirect()->route('admin.archives.index')
            ->with('success', "Le dossier « {$matricule} » a été supprimé (avec son parcours et ses notes).");
    }

    /**
     * Export CSV du parcours universitaire (architecture export : PDF/Excel
     * pourront s'ajouter côté service). Autorisation + audit systématiques.
     */
    public function export(DossierAcademique $dossier): StreamedResponse
    {
        Gate::authorize('view', $dossier);

        $this->audit->tracer(
            $dossier->etudiant,
            'export_dossier',
            'dossier',
            $dossier->id,
            null,
            ['format' => 'csv'],
        );

        $inscriptions = $dossier->inscriptions()
            ->with(['anneeUniversitaire', 'semestres.notes.matiere'])
            ->orderBy('annee_universitaire_id')
            ->get();

        $filename = "archive-{$dossier->matricule}.csv";

        return response()->streamDownload(function () use ($inscriptions) {
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF"); // BOM UTF-8 pour Excel
            fputcsv($sortie, ['Année universitaire', 'Niveau', 'Filière', 'Moyenne annuelle', 'Crédits obtenus', 'Crédits totaux', 'Décision']);

            foreach ($inscriptions as $inscription) {
                // Audit §P3-18 : neutralisation des formules (CSV injection),
                // comme tous les autres canaux d'export (§62).
                fputcsv($sortie, [
                    $this->csv->neutraliserFormule((string) ($inscription->anneeUniversitaire?->libelle ?? '')),
                    $this->csv->neutraliserFormule((string) ($inscription->niveau ?? '')),
                    $this->csv->neutraliserFormule((string) ($inscription->filiere ?? '')),
                    $inscription->moyenne_annuelle,
                    $inscription->credits_obtenus,
                    $inscription->credits_total,
                    $this->csv->neutraliserFormule((string) ($inscription->decision ?? '')),
                ]);
            }

            fclose($sortie);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Basculer le statut d'un dossier (actif / clos) avec motif et audit.
     */
    public function toggleStatut(Request $request, DossierAcademique $dossier): RedirectResponse
    {
        Gate::authorize('update', $dossier);

        $validated = $request->validate([
            'statut' => 'required|in:actif,clos',
            'motif' => 'nullable|string|max:255',
        ]);

        $ancienStatut = $dossier->statut;
        $motif = trim((string) ($validated['motif'] ?? ''));

        $observation = $dossier->observation;
        if ($motif !== '') {
            $observation = trim($observation."\n[".now()->format('d/m/Y H:i')."] Changement statut: {$validated['statut']} — Motif: {$motif}");
        }

        $dossier->update([
            'statut' => $validated['statut'],
            'observation' => $observation,
        ]);

        $this->audit->tracer(
            $dossier->etudiant,
            HistoriqueAcademique::ACTION_MODIFICATION_DOSSIER,
            'dossier',
            $dossier->id,
            ['statut' => $ancienStatut],
            ['statut' => $validated['statut'], 'motif' => $motif ?: null],
        );

        $libelle = $validated['statut'] === 'clos' ? 'clos (archivé)' : 'réactivé';

        return redirect()->route('admin.archives.show', $dossier)
            ->with('success', "Le dossier « {$dossier->matricule} » est désormais {$libelle}.");
    }

    /**
     * Export CSV global du registre des archives avec filtres et isolation.
     */
    public function exportRegistre(Request $request): StreamedResponse
    {
        Gate::authorize('viewAny', DossierAcademique::class);

        $faculteRestreinteId = $request->user()->faculteRestreinteId();

        $query = DossierAcademique::query()
            ->join('etudiants', 'etudiants.id', '=', 'archives_etudiants.etudiant_id')
            ->join('facultes', 'facultes.id', '=', 'etudiants.faculte_id')
            ->select('archives_etudiants.*');

        if ($faculteRestreinteId !== null) {
            $query->where('etudiants.faculte_id', $faculteRestreinteId);
        } elseif ($faculteId = $request->input('faculte_id')) {
            $query->where('etudiants.faculte_id', $faculteId);
        }

        if ($recherche = trim((string) $request->string('search'))) {
            $motif = Recherche::contient($recherche);
            $query->where(function ($q) use ($motif) {
                $q->where('etudiants.nom', 'like', $motif)
                    ->orWhere('etudiants.prenom', 'like', $motif)
                    ->orWhere('etudiants.cin', 'like', $motif)
                    ->orWhere('etudiants.email', 'like', $motif)
                    ->orWhere('etudiants.num_inscription', 'like', $motif)
                    ->orWhere('archives_etudiants.matricule', 'like', $motif);
            });
        }

        if ($anneeId = $request->input('annee_universitaire_id')) {
            $query->whereExists(function ($q) use ($anneeId) {
                $q->select(\DB::raw(1))
                    ->from('inscriptions_academiques')
                    ->whereColumn('inscriptions_academiques.etudiant_id', 'etudiants.id')
                    ->where('inscriptions_academiques.annee_universitaire_id', $anneeId);
            });
        }

        if ($niveau = $request->input('niveau')) {
            $query->whereExists(function ($q) use ($niveau) {
                $q->select(\DB::raw(1))
                    ->from('inscriptions_academiques')
                    ->whereColumn('inscriptions_academiques.etudiant_id', 'etudiants.id')
                    ->where('inscriptions_academiques.niveau', $niveau);
            });
        }

        if ($statut = $request->input('statut')) {
            $query->where('archives_etudiants.statut', $statut);
        }

        if ($programmeId = $request->input('programme_id')) {
            $query->whereExists(function ($q) use ($programmeId) {
                $q->select(\DB::raw(1))
                    ->from('inscriptions_academiques')
                    ->whereColumn('inscriptions_academiques.etudiant_id', 'etudiants.id')
                    ->where('inscriptions_academiques.programme_id', $programmeId);
            });
        }

        $dossiers = $query->with(['etudiant.faculte'])
            ->withCount('inscriptions')
            ->orderBy('etudiants.nom')
            ->orderBy('archives_etudiants.id')
            ->get();

        Log::info('export.archives.registre', [
            'utilisateur_id' => $request->user()->id,
            'faculte_id' => $faculteRestreinteId,
            'nombre_dossiers' => $dossiers->count(),
        ]);

        $filename = 'registre-archives-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($dossiers) {
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF");
            fputcsv($sortie, ['Matricule', 'Nom complet', 'CIN masquée', 'Faculté', 'Niveau', 'Années inscrites', 'Statut', 'Date archivage']);

            foreach ($dossiers as $dossier) {
                fputcsv($sortie, [
                    $this->csv->neutraliserFormule((string) $dossier->matricule),
                    $this->csv->neutraliserFormule((string) $dossier->etudiant->nom_complet),
                    $this->csv->neutraliserFormule((string) $dossier->etudiant->cin_masquee),
                    $this->csv->neutraliserFormule((string) ($dossier->etudiant->faculte?->code ?? '')),
                    $this->csv->neutraliserFormule((string) ($dossier->etudiant->niveau ?? '')),
                    $dossier->inscriptions_count,
                    $dossier->statut,
                    $dossier->date_archivage?->format('d/m/Y') ?? '',
                ]);
            }

            fclose($sortie);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
