<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\GeneratePopulationExport;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\ExportTemplate;
use App\Models\Faculte;
use App\Models\ImportReport;
use App\Models\IntegrationLog;
use App\Models\Matiere;
use App\Modules\Enseignement\Models\SousGroupe;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Teacher;
use App\Modules\StudentDossiers\Contracts\PdfGeneratorInterface;
use App\Services\Exports\ListeEtudiantsService;
use App\Services\Io\ExportOrchestrator;
use App\Services\Io\Exports\ExportColumnPolicy;
use App\Services\Io\IoFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Moteur central de listes et impression (§38) : UNE requête (même
 * population) pour prévisualisation, CSV, XLSX, PDF et impression.
 * Scopes + colonnes serveur, jamais de sensible sans permission.
 */
class ListesController extends Controller
{
    private const FORMATS = ['csv', 'xlsx', 'pdf'];

    /** PDF synchrone plafonné (au-delà : CSV/XLSX en flux). */
    private const PDF_MAX_LIGNES = 1000;

    /**
     * Seuil async CSV/XLSX (§2.7) : moteur en curseur + streaming tient
     * ≤5000 lignes en requête HTTP (<30 s, mémoire bornée) ; au-delà :
     * job + stockage privé + notification + téléchargement. PDF toujours
     * synchrone ≤1000 (mise en page mémoire).
     */
    private const SEUIL_ASYNC = 5000;

    public function __construct(
        private readonly ListeEtudiantsService $listes,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Etudiant::class);

        return view('admin.listes.index', [
            'filtres' => $this->listesReferentiels($request),
            'catalogue' => ExportColumnPolicy::catalogue('students'),
        ]);
    }

    public function preview(Request $request): View
    {
        Gate::authorize('viewAny', Etudiant::class);

        $filtres = $this->filtresValides($request);
        $query = $this->listes->requete($filtres, $request->user());
        $total = (clone $query)->count();

        return view('admin.listes.preview', [
            'filtres' => $filtres,
            'total' => $total,
            'etudiants' => $query->paginate(25)->withQueryString(),
            'contexte' => $this->contexteTexte($filtres),
            'regroupement' => ListeEtudiantsService::normaliserRegroupement($filtres['regroupement'] ?? null),
            'statistiques' => [
                'total' => $total,
                'actifs' => (clone $query)->where('statut', 'valide')->count(),
                'sans_groupe' => (clone $query)->whereNull('groupe_id')->count(),
                'groupes' => (clone $query)->whereNotNull('groupe_id')->count(),
            ],
            'profils' => ExportTemplate::where('user_id', $request->user()->id)
                ->where('type', 'listes')->orderBy('name')->get(),
        ]);
    }

    public function exporter(Request $request, string $format, PdfGeneratorInterface $pdf): StreamedResponse|RedirectResponse
    {
        abort_unless(in_array($format, self::FORMATS, true), 404);
        Gate::authorize('viewAny', Etudiant::class);
        abort_unless($request->user()->can('data.export'), 403);

        $filtres = $this->filtresValides($request);
        $colonnes = $this->colonnesValides($request);
        $regroupement = ListeEtudiantsService::normaliserRegroupement($filtres['regroupement'] ?? null);

        $query = $this->listes->requete($filtres, $request->user());
        $total = (clone $query)->count();

        if ($total === 0) {
            return back()->withErrors(['export' => 'Aucun résultat. Vérifiez les filtres sélectionnés.']);
        }

        if ($format === 'pdf' && $total > self::PDF_MAX_LIGNES) {
            return back()->withErrors(['export' => 'PDF limité à '.self::PDF_MAX_LIGNES." lignes ({$total} trouvées) : utilisez CSV ou XLSX."]);
        }

        // Volumineux CSV/XLSX : job différé + notification (§2.7-2.9).
        if ($format !== 'pdf' && $total > self::SEUIL_ASYNC) {
            $rapport = ImportReport::create([
                'user_id' => $request->user()->id,
                'integration' => 'listes',
                'direction' => 'export',
                'type' => 'listes.etudiants',
                'format' => $format,
                'external_reference' => 'EXP-'.now()->format('Y').'-'.strtoupper(Str::random(6)),
                'status' => 'pending',
                'preview' => [
                    'filtres' => $filtres, 'columns' => $colonnes,
                    'regroupement' => $regroupement, 'total_estime' => $total,
                ],
                'total_rows' => $total,
                'expires_at' => now()->addHours((int) config('integrations.export_retention_hours', 168)),
            ]);

            GeneratePopulationExport::dispatch(
                $rapport->id, (int) $request->user()->id, $format, $filtres, $colonnes, $regroupement,
            );

            return redirect()->route('admin.listes.exports')
                ->with('success', "Export volumineux ({$total} lignes) : traitement en cours, vous serez notifié.");
        }

        $debut = microtime(true);
        $requestId = (string) Str::uuid();
        $reference = 'EXP-'.now()->format('Y').'-'.strtoupper(Str::random(6));

        try {
            $orientation = $this->orientationPour($filtres, $colonnes);

            if ($format === 'pdf') {
                $donneesPdf = $regroupement !== null
                    ? $this->listes->sectionsRegroupees($query, $request->user(), $colonnes, $regroupement)
                    : $this->listes->jeuDonnees($query, $request->user(), $colonnes) + ['sections' => null, 'total' => $total];

                $contenu = $pdf->generer(view('pdf.liste', [
                    'institution' => config('app.name'),
                    'titreDocument' => $filtres['titre'] ?? 'Liste des étudiants',
                    'reference' => $reference,
                    'dateGeneration' => now()->format('d/m/Y H:i'),
                    'contexte' => $this->contexteTexte($filtres),
                    'metadonnees' => $this->metadonnees($filtres, $request, $total, $regroupement),
                    'champsEntete' => $filtres['champs_entete'] ?? null,
                    'entetes' => $donneesPdf['entetes'],
                    'lignes' => $donneesPdf['sections'] === null ? $donneesPdf['lignes'] : [],
                    'sections' => $donneesPdf['sections'],
                    'signature' => (bool) ($filtres['emargement'] ?? false),
                    'zoneSignature' => (bool) ($filtres['zone_signature'] ?? false),
                    'orientation' => $orientation,
                ])->render(), [
                    'paper' => 'A4',
                    'orientation' => $orientation,
                ]);
                $mime = 'application/pdf';
            } else {
                $jeu = $regroupement !== null
                    ? $this->aplatirSections($this->listes->sectionsRegroupees($query, $request->user(), $colonnes, $regroupement))
                    : $this->listes->jeuDonnees($query, $request->user(), $colonnes);
                $contenu = IoFactory::serializerPour($format)->serialize($jeu['entetes'], $jeu['lignes']);
                $mime = IoFactory::serializerPour($format)->mimeType();
            }

            IntegrationLog::tracer(
                'export', 'export', 'export.listes', 'completed',
                null, 200, (int) ((microtime(true) - $debut) * 1000), null, $requestId,
                $reference,
            );

            return response()->streamDownload(
                function () use ($contenu) {
                    echo $contenu;
                },
                $this->nomFichier($filtres, $format),
                ['Content-Type' => $mime]
            );
        } catch (\Throwable $e) {
            IntegrationLog::tracer(
                'export', 'export', 'export.listes', 'failed',
                null, null, (int) ((microtime(true) - $debut) * 1000), 'export_failed', $requestId
            );

            throw $e;
        }
    }

    public function imprimer(Request $request): View
    {
        Gate::authorize('viewAny', Etudiant::class);
        abort_unless($request->user()->can('data.export'), 403);

        $filtres = $this->filtresValides($request);
        $regroupement = ListeEtudiantsService::normaliserRegroupement($filtres['regroupement'] ?? null);
        $colonnesSouhaitees = $this->colonnesValides($request);
        $query = $this->listes->requete($filtres, $request->user());
        $total = (clone $query)->count();

        // Catalogue autorisé pour l'utilisateur connecté
        $personnelAutorise = $request->user()->hasPermission('exports.personal');
        $catalogueColonnes = array_merge(['numero'], ExportColumnPolicy::catalogue('students'));
        if (! $personnelAutorise) {
            $catalogueColonnes = array_values(array_diff($catalogueColonnes, ['email', 'telephone']));
        }

        if ($regroupement !== null) {
            $resultat = $this->listes->sectionsRegroupees($query->limit(1000), $request->user(), $catalogueColonnes, $regroupement);
            $jeu = ['entetes' => $resultat['entetes'], 'lignes' => []];
        } else {
            $jeu = $this->listes->jeuDonnees($query->limit(1000), $request->user(), $catalogueColonnes);
            $resultat = ['sections' => null];
        }

        $metadonnees = $this->metadonnees($filtres, $request, $total, $regroupement);

        $colonnesInitiales = $colonnesSouhaitees ?? (
            ! empty($filtres['groupe_id'])
                ? array_values(array_intersect(['numero', 'matricule', 'cin', 'nom', 'prenom', 'statut'], $catalogueColonnes))
                : array_values(array_intersect(['numero', 'matricule', 'cin', 'nom', 'prenom', 'programme', 'niveau', 'groupe', 'statut'], $catalogueColonnes))
        );

        return view('admin.listes.print', [
            'titre' => $filtres['titre'] ?? 'Liste des étudiants',
            'contexte' => $this->contexteTexte($filtres),
            'jeu' => $jeu,
            'sections' => $resultat['sections'],
            'regroupement' => $regroupement !== null ? ListeEtudiantsService::libelleRegroupement($regroupement) : null,
            'metadonnees' => $metadonnees,
            'filtres' => $filtres,
            'catalogueColonnes' => $catalogueColonnes,
            'colonnesInitiales' => $colonnesInitiales,
            'champsEnteteInitiaux' => $filtres['champs_entete'] ?? null,
            'emargementInitial' => (bool) ($filtres['emargement'] ?? false),
            'zoneSignatureInitiale' => (bool) ($filtres['zone_signature'] ?? false),
        ]);
    }

    /**
     * Historique des exports listes (§2.10) : ImportReport réutilisé
     * (jamais de PII — filtres/colonnes/regroupement + compteurs).
     */
    public function exports(Request $request): View
    {
        Gate::authorize('viewAny', Etudiant::class);
        abort_unless($request->user()->can('data.export'), 403);

        $restreint = $request->user()->faculteRestreinteId() !== null;

        return view('admin.listes.exports', [
            'rapports' => ImportReport::where('type', 'listes.etudiants')
                ->when($restreint, fn ($q) => $q->where('user_id', $request->user()->id))
                ->latest()->paginate(15),
        ]);
    }

    public function telechargerExport(Request $request, ImportReport $rapport, ExportOrchestrator $orchestrator)
    {
        Gate::authorize('viewAny', Etudiant::class);
        abort_unless($request->user()->can('data.export'), 403);
        abort_if($rapport->type !== 'listes.etudiants' || $rapport->direction !== 'export', 404);
        abort_unless(
            (int) $rapport->user_id === (int) $request->user()->id || $request->user()->estSuperAdmin(),
            403,
        );

        return $orchestrator->telecharger($rapport, $request->user());
    }

    /**
     * Profils d'export (§18) : réutilise ExportTemplate (type `listes`)
     * — dataset + filtres + colonnes + tri + format + titre.
     */
    public function storeProfil(Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Etudiant::class);

        $valide = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'format' => ['required', 'in:csv,xlsx,pdf'],
        ]);

        $filtres = $this->filtresValides($request);

        ExportTemplate::updateOrCreate(
            ['user_id' => $request->user()->id, 'name' => $valide['name']],
            [
                'type' => 'listes',
                'profil' => 'personnalise',
                'format' => $valide['format'],
                'filters' => $filtres,
                'columns' => $this->colonnesValides($request),
            ]
        );

        return back()->with('success', "Profil « {$valide['name']} » enregistré.");
    }

    public function destroyProfil(Request $request, ExportTemplate $profil): RedirectResponse
    {
        abort_if((int) $profil->user_id !== (int) $request->user()->id || $profil->type !== 'listes', 404);

        $profil->delete();

        return back()->with('success', 'Profil supprimé.');
    }

    /** @return array<string, mixed> */
    private function filtresValides(Request $request): array
    {
        $valide = $request->validate([
            'annee_universitaire_id' => ['nullable', 'integer', 'exists:annees_universitaires,id'],
            'faculte_id' => ['nullable', 'integer', 'exists:facultes,id'],
            'programme_id' => ['nullable', 'integer', 'exists:programmes,id'],
            'niveau' => ['nullable', 'in:L1,L2,L3,M1,M2,D1,D2,D3'],
            'semestre' => ['nullable', 'integer', 'in:1,2'],
            'groupe_id' => ['nullable', 'integer', 'exists:groups,id'],
            'sous_groupe_id' => ['nullable', 'integer', 'exists:sous_groupes,id'],
            'ue_id' => ['nullable', 'integer', 'exists:unites_enseignement,id'],
            'matiere_id' => ['nullable', 'integer', 'exists:matieres,id'],
            'enseignant_id' => ['nullable', 'integer', 'exists:teachers,id'],
            'statut' => ['nullable', 'in:en_attente,valide,rejete'],
            'sans_groupe' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:120'],
            // Même borne et même moteur que le registre (ListeEtudiantsService)
            // pour que PDF/Excel/CSV/impression et exports différés conservent
            // exactement le filtre d'ancienneté de dépôt.
            'depose_depuis' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'tri' => ['nullable', 'in:'.implode(',', ListeEtudiantsService::TRIS)],
            'sens' => ['nullable', 'in:asc,desc'],
            'colonnes' => ['nullable'],
            'titre' => ['nullable', 'string', 'max:150'],
            'regroupement' => ['nullable', 'string', 'max:20'],
            'orientation' => ['nullable', 'in:portrait,paysage,auto'],
            'emargement' => ['nullable', 'boolean'],
            'zone_signature' => ['nullable', 'boolean'],
            'champs_entete' => ['nullable'],
        ]);

        // Normalisation champs_entete (array ou séparés par virgule)
        $champsEntete = $request->input('champs_entete');
        if (is_string($champsEntete)) {
            $champsEntete = array_filter(array_map('trim', explode(',', $champsEntete)));
        }
        if (is_array($champsEntete)) {
            $catalogueEntete = [
                'etablissement', 'faculte', 'departement', 'annee', 'programme',
                'parcours', 'groupe', 'niveau', 'semestre', 'effectif',
                'date', 'utilisateur', 'filtres', 'visa',
            ];
            $valide['champs_entete'] = array_values(array_intersect($catalogueEntete, array_map('strval', $champsEntete)));
        } else {
            $valide['champs_entete'] = null;
        }

        // Scope serveur : un admin_faculte ne filtre jamais hors périmètre.
        if ($request->user()->faculteRestreinteId() !== null) {
            $valide['faculte_id'] = $request->user()->faculteRestreinteId();
        }

        $valide['regroupement'] = ListeEtudiantsService::normaliserRegroupement($valide['regroupement'] ?? null);

        return $valide;
    }

    /** @return array<int, string>|null */
    private function colonnesValides(Request $request): ?array
    {
        $colonnes = $request->input('colonnes');

        if (is_string($colonnes)) {
            $colonnes = array_filter(array_map('trim', explode(',', $colonnes)));
        }

        if (! is_array($colonnes)) {
            return null;
        }

        $catalogue = array_merge(['numero'], ExportColumnPolicy::catalogue('students'));

        return array_values(array_intersect($catalogue, array_map('strval', $colonnes)));
    }

    /** @return array<string, mixed> */
    private function listesReferentiels(Request $request): array
    {
        $faculteId = $request->user()->faculteRestreinteId();

        return [
            'annees' => AnneeUniversitaire::orderByDesc('libelle')->get(),
            'facultes' => $faculteId === null ? Faculte::orderBy('nom')->get() : collect(),
            'programmes' => Programme::orderBy('code')
                ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))->get(),
            'groupes' => Group::orderBy('code')
                ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))->get(),
            'sous_groupes' => SousGroupe::orderBy('code')->limit(500)->get(),
            'matieres' => Matiere::orderBy('code')->limit(500)->get(),
            'enseignants' => Teacher::where('actif', true)
                ->when($faculteId !== null, fn ($q) => $q->where(
                    fn ($w) => $w->whereNull('departement_id')
                        ->orWhereHas('departement', fn ($d) => $d->where('faculte_id', $faculteId))
                ))
                ->orderBy('nom')->limit(500)->get(),
        ];
    }

    /** @param array<string, mixed> $filtres */
    private function contexteTexte(array $filtres): string
    {
        return implode(' · ', $this->contexteDetails($filtres)) ?: 'Tous les étudiants du périmètre';
    }

    /**
     * Contexte lisible (§2.3) : libellés résolus (jamais d'IDs bruts).
     *
     * @param  array<string, mixed>  $filtres
     * @return array<int, string>
     */
    private function contexteDetails(array $filtres): array
    {
        $morceaux = [];

        $resolutions = [
            'annee_universitaire_id' => ['Année', fn ($id) => AnneeUniversitaire::find($id)?->libelle ?? $id],
            'faculte_id' => ['Faculté', fn ($id) => Faculte::find($id)?->nom ?? $id],
            'programme_id' => ['Programme', fn ($id) => Programme::find($id)?->code ?? $id],
            'groupe_id' => ['Groupe', fn ($id) => Group::find($id)?->code ?? $id],
            'sous_groupe_id' => ['Sous-groupe', fn ($id) => SousGroupe::find($id)?->code ?? $id],
            'matiere_id' => ['ECUE', fn ($id) => Matiere::find($id)?->code ?? $id],
            'enseignant_id' => ['Enseignant', fn ($id) => Teacher::find($id)?->nomComplet() ?? $id],
        ];

        foreach ($resolutions as $cle => [$libelle, $resoudre]) {
            if (! empty($filtres[$cle])) {
                $morceaux[] = "{$libelle} : {$resoudre($filtres[$cle])}";
            }
        }

        foreach (['niveau' => 'Niveau', 'semestre' => 'Semestre', 'statut' => 'Statut'] as $cle => $libelle) {
            if (! empty($filtres[$cle])) {
                $morceaux[] = "{$libelle} : {$filtres[$cle]}";
            }
        }

        if (! empty($filtres['sans_groupe'])) {
            $morceaux[] = 'Sans groupe uniquement';
        }

        return $morceaux;
    }

    /**
     * Page de garde / métadonnées PDF (§2.3-2.4) : référence existante
     * réutilisée (aucun nouveau système de références).
     *
     * @param  array<string, mixed>  $filtres
     * @return array<string, mixed>
     */
    private function metadonnees(array $filtres, Request $request, int $total, ?string $regroupement): array
    {
        $faculte = ! empty($filtres['faculte_id']) ? Faculte::find($filtres['faculte_id']) : null;
        $annee = ! empty($filtres['annee_universitaire_id']) ? AnneeUniversitaire::find($filtres['annee_universitaire_id']) : null;
        $programme = ! empty($filtres['programme_id']) ? Programme::find($filtres['programme_id']) : null;
        $groupe = ! empty($filtres['groupe_id']) ? Group::with(['faculte', 'anneeUniversitaire', 'programme'])->find($filtres['groupe_id']) : null;

        if ($groupe !== null) {
            $faculte ??= $groupe->faculte;
            $annee ??= $groupe->anneeUniversitaire;
            $programme ??= $groupe->programme;
        }

        return [
            'etablissement' => config('app.name'),
            'faculte' => $faculte?->nom,
            'departement' => $programme?->departement?->nom,
            'annee' => $annee?->libelle,
            'programme' => $programme !== null ? "{$programme->code} — {$programme->nom}" : null,
            'parcours' => $programme?->specialite?->nom,
            'groupe' => $groupe !== null ? ($groupe->code.($groupe->nom ? " — {$groupe->nom}" : '')) : null,
            'niveau' => $filtres['niveau'] ?? $groupe?->niveau,
            'semestre' => isset($filtres['semestre']) ? 'S'.$filtres['semestre'] : null,
            'utilisateur' => $request->user()->name ?? $request->user()->email,
            'total' => $total,
            'effectif' => $total,
            'date_generation' => now()->format('d/m/Y à H:i'),
            'regroupement' => $regroupement !== null ? ListeEtudiantsService::libelleRegroupement($regroupement) : null,
        ];
    }

    /** @param array<string, mixed> $filtres */
    private function orientationPour(array $filtres, ?array $colonnes): string
    {
        $choix = $filtres['orientation'] ?? 'auto';

        if ($choix === 'paysage') {
            return 'landscape';
        }

        if ($choix === 'portrait') {
            return 'portrait';
        }

        $nbColonnes = $colonnes !== null ? count($colonnes) : count(ExportColumnPolicy::catalogue('students')) + 1;

        return $nbColonnes > 6 ? 'landscape' : 'portrait';
    }

    /**
     * @param  array{entetes: array<int, string>, total: int, sections: array<int, array{titre: string, effectif: int, lignes: array<int, array<string, mixed>>}>}  $resultat
     * @return array{entetes: array<int, string>, lignes: array<int, array<string, mixed>>}
     */
    private function aplatirSections(array $resultat): array
    {
        $lignes = [];

        foreach ($resultat['sections'] as $section) {
            foreach ($section['lignes'] as $ligne) {
                $lignes[] = ['section' => $section['titre']] + $ligne;
            }
        }

        return ['entetes' => array_merge(['section'], $resultat['entetes']), 'lignes' => $lignes];
    }

    /** @param array<string, mixed> $filtres */
    private function nomFichier(array $filtres, string $format): string
    {
        $morceaux = ['liste-etudiants'];

        foreach (['niveau', 'statut'] as $cle) {
            if (! empty($filtres[$cle])) {
                $morceaux[] = strtolower((string) $filtres[$cle]);
            }
        }

        if (! empty($filtres['groupe_id'])) {
            $groupe = Group::find($filtres['groupe_id']);
            $morceaux[] = strtolower((string) ($groupe?->code ?? 'groupe'));
        }

        $morceaux[] = now()->format('Ymd');

        return implode('-', $morceaux).".{$format}";
    }
}
