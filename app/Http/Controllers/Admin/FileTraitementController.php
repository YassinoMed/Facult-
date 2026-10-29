<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Modules\Referentiel\Models\Programme;
use App\Services\Exports\ListeEtudiantsService;
use App\Services\FileTraitementService;
use App\Services\PreferencesService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Contrôleur de la file administrative de traitement des inscriptions (LOOP 18).
 *
 * Règles :
 * - Scoping faculté strict appliqué sur toute requête et KPI.
 * - Tri par ancienneté (FIFO par défaut) pour une équité de traitement.
 * - Mesure objective des volumes et délais (anciens > 48h).
 * - Fiche structurée, motifs normalisés et traçabilité d'audit.
 */
class FileTraitementController extends Controller
{
    public function __construct(
        private readonly FileTraitementService $fileService,
        private readonly ListeEtudiantsService $listes,
    ) {}

    /**
     * File de traitement des dossiers d'inscription avec indicateurs et filtres.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Etudiant::class);

        $utilisateur = $request->user();
        $faculteRestreinteId = $utilisateur->faculteRestreinteId();

        $valide = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'faculte_id' => ['nullable', 'integer', 'exists:facultes,id'],
            'programme_id' => ['nullable', 'integer', 'exists:programmes,id'],
            'niveau' => ['nullable', 'in:L1,L2,L3,M1,M2,D1,D2,D3'],
            'statut' => ['nullable', 'in:en_attente,valide,rejete'],
            'action_attendue' => ['nullable', 'in:'.implode(',', array_keys(FileTraitementService::ACTIONS_ATTENDUES))],
            'categorie' => ['nullable', 'in:'.implode(',', array_keys(FileTraitementService::CATEGORIES_FILE))],
            'depose_depuis' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'tri' => ['nullable', 'in:'.implode(',', ListeEtudiantsService::TRIS)],
            'sens' => ['nullable', 'in:asc,desc'],
        ]);

        if ($faculteRestreinteId !== null) {
            $valide['faculte_id'] = $faculteRestreinteId;
        }

        // Tri par défaut : FIFO (les dossiers les plus anciens en attente d'abord)
        if (empty($valide['tri'])) {
            $valide['tri'] = 'created_at';
            $valide['sens'] = $valide['sens'] ?? 'asc';
        }

        // Requête de base via le moteur central ListeEtudiantsService
        $query = $this->listes->requete($valide, $utilisateur);

        // La file n'affiche ni sous-groupe ni affectation détaillée : on
        // repart d'un eager-loading explicite (une requête inutile en
        // moins par page), sans modifier le moteur d'export partagé.
        $query->setEagerLoads([]);

        // Eager-loading complet pour éviter tout N+1
        $query->with([
            'faculte',
            'groupe',
            'programmeRef',
            'niveauRef',
            'inscriptionsAcademiques' => fn ($q) => $q->latest('annee_universitaire_id')->limit(1),
        ]);

        // Filtrage spécifique par action attendue
        if (! empty($valide['action_attendue'])) {
            $action = $valide['action_attendue'];

            match ($action) {
                FileTraitementService::ACTION_A_AFFECTER => $query
                    ->where('statut', 'valide')
                    ->whereNull('groupe_id'),

                FileTraitementService::ACTION_TRAITE => $query
                    ->where(function ($q) {
                        $q->where('statut', 'rejete')
                            ->orWhere(fn ($sub) => $sub->where('statut', 'valide')->whereNotNull('groupe_id'));
                    }),

                FileTraitementService::ACTION_COMPLEMENT_ATTENDU => $query
                    ->where('statut', 'en_attente')
                    ->whereIn('id', function ($sub) {
                        $sub->select('etudiant_id')->from('dossier_corrections')->where('statut', 'proposee');
                    }),

                FileTraitementService::ACTION_ANOMALIE_DETECTEE => $query
                    ->where('statut', 'en_attente')
                    ->whereIn('id', function ($sub) {
                        $sub->select('etudiant_id')->from('dossier_verifications')
                            ->whereIn('id', function ($sq) {
                                $sq->selectRaw('MAX(id)')->from('dossier_verifications')->groupBy('etudiant_id');
                            })
                            ->whereIn('verdict', ['BLOQUANT', 'A_VERIFIER']);
                    }),

                FileTraitementService::ACTION_A_INSTRUIRE => $query
                    ->where('statut', 'en_attente')
                    ->whereNotIn('id', function ($sub) {
                        $sub->select('etudiant_id')->from('dossier_corrections')->where('statut', 'proposee');
                    })
                    ->whereNotIn('id', function ($sub) {
                        $sub->select('etudiant_id')->from('dossier_verifications')
                            ->whereIn('id', function ($sq) {
                                $sq->selectRaw('MAX(id)')->from('dossier_verifications')->groupBy('etudiant_id');
                            })
                            ->whereIn('verdict', ['BLOQUANT', 'A_VERIFIER']);
                    }),

                default => null,
            };
        }

        // LOOP C : filtre par catégorie dérivée — traduction SQL des mêmes
        // prédicats que la catégorisation (jamais de recalcul applicatif).
        if (! empty($valide['categorie'])) {
            $this->fileService->contrainteCategorie($query, $valide['categorie']);
        }

        // Pagination configurable (Phase B) : préférence utilisateur
        // bornée [5,100] par PreferencesService, défaut 12. Les KPIs
        // restent calculés sur tout le périmètre filtré, jamais sur la page.
        $preferences = app(PreferencesService::class)->lire((int) $utilisateur->id);

        // Même builder que la liste : les KPI décrivent exactement la
        // population filtrée (D-01). On retire uniquement la présentation
        // (ordre, colonnes, eager-loading) avant les agrégats.
        $kpiBase = clone $query;
        $kpiBase->reorder();
        $kpiBase->getQuery()->columns = null;
        $kpiBase->setEagerLoads([]);

        $etudiants = $query->paginate($preferences['lignes_par_page'])->withQueryString();

        // Enrichissement des métriques par lot (zéro N+1)
        $this->fileService->enrichirEtudiants($etudiants);

        // KPIs consolidés sur le périmètre filtré (faculté + filtres métier)
        $kpis = $this->fileService->kpis($utilisateur, $valide['faculte_id'] ?? null, $kpiBase);

        // Référentiels des filtres : strictement dans le périmètre de
        // l'utilisateur. Un admin facultaire ne doit jamais recevoir les
        // programmes d'une autre faculté dans le HTML.
        $facultes = $faculteRestreinteId !== null
            ? Faculte::where('id', $faculteRestreinteId)->get()
            : Faculte::orderBy('nom')->get();

        $programmes = Programme::query()
            ->actifs()
            ->when($valide['faculte_id'] ?? null, fn ($q, $fid) => $q->where('faculte_id', $fid))
            ->orderBy('nom')
            ->get();

        return view('admin.inscriptions.file-traitement', [
            'etudiants' => $etudiants,
            'kpis' => $kpis,
            'filtres' => $valide,
            'filtresActifs' => $this->compterFiltresActifs($valide, $faculteRestreinteId),
            'facultes' => $facultes,
            'programmes' => $programmes,
            'actionsAttendues' => FileTraitementService::ACTIONS_ATTENDUES,
            'categoriesFile' => FileTraitementService::CATEGORIES_FILE,
            'motifsNormalises' => FileTraitementService::MOTIFS_NORMALISES,
        ]);
    }

    /**
     * Nombre de filtres métier activement choisis (tri/sens et périmètre
     * forcé exclus) pour l'indicateur d'interface.
     *
     * @param  array<string, mixed>  $valide
     */
    private function compterFiltresActifs(array $valide, ?int $faculteRestreinteId): int
    {
        $cles = ['search', 'statut', 'action_attendue', 'categorie', 'depose_depuis', 'programme_id', 'niveau'];

        if ($faculteRestreinteId === null) {
            $cles[] = 'faculte_id';
        }

        $actifs = 0;
        foreach ($cles as $cle) {
            if (isset($valide[$cle]) && $valide[$cle] !== '' && $valide[$cle] !== null) {
                $actifs++;
            }
        }

        return $actifs;
    }

    /**
     * Ajout d'une note d'instruction administrative tracée avec motif normalisé.
     */
    public function commenter(Request $request, Etudiant $etudiant): RedirectResponse|JsonResponse
    {
        Gate::authorize('update', $etudiant);

        $utilisateur = $request->user();
        if (method_exists($utilisateur, 'peutAccederFaculte') && ! $utilisateur->peutAccederFaculte((int) $etudiant->faculte_id)) {
            abort(403, 'Accès interdit : étudiant hors de votre faculté.');
        }

        // En cas d'erreur de validation, la modale est rouverte avec la
        // saisie conservée (Phase D) : l'identifiant ciblé est flashé.
        try {
            $valide = $request->validate([
                'commentaire' => ['required', 'string', 'min:3', 'max:1000'],
                'motif_cle' => ['nullable', 'string', 'in:'.implode(',', array_keys(FileTraitementService::MOTIFS_NORMALISES))],
            ]);
        } catch (ValidationException $e) {
            // Voie JSON : comportement framework intact (422 JSON).
            if ($request->wantsJson()) {
                throw $e;
            }

            return back()
                ->withErrors($e->errors())
                ->withInput()
                ->with('note_etudiant_id', $etudiant->id);
        }

        try {
            $this->fileService->ajouterCommentaireInstruction(
                $etudiant,
                $utilisateur,
                $valide['commentaire'],
                $valide['motif_cle'] ?? null
            );
        } catch (DomainException $e) {
            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => $e->getMessage()], 422)
                : back()->withErrors(['commentaire' => $e->getMessage()]);
        }

        $message = "Note d'instruction enregistrée pour l'étudiant {$etudiant->nom_complet}.";

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
            ]);
        }

        return back()->with('status', $message);
    }
}
