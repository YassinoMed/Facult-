<?php

namespace App\Modules\StudentDossiers\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Models\HistoriqueAcademique;
use App\Modules\StudentDossiers\Models\StudentDocument;
use App\Modules\StudentDossiers\Models\StudentDossier;
use App\Modules\StudentDossiers\Resources\StudentDocumentResource;
use App\Modules\StudentDossiers\Resources\StudentDossierResource;
use App\Modules\StudentDossiers\Services\StudentDossierService;
use App\Services\AuditService;
use App\Support\Recherche;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * API v1 — dossiers administratifs permanents (module StudentDossiers).
 *
 * Lecture + écritures contrôlées (§16) : les Resources exposent des
 * métadonnées, jamais `storage_path` ni le contenu des fichiers. Chaque
 * route porte son scope Sanctum + throttle ; les filtres passent par une
 * liste blanche et la pagination est bornée (§58-59).
 *
 * Isolation par faculté (§17) : un admin_faculte ne voit et ne modifie que
 * les dossiers des étudiants de sa faculté — vérifié en base à chaque
 * requête, avec un 404 (jamais 403) hors périmètre.
 */
class StudentDossierApiController extends Controller
{
    public function __construct(
        private readonly StudentDossierService $dossiers,
        private readonly AuditService $audit,
    ) {}

    /**
     * Liste paginée des dossiers (scope archives:read).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = $this->parPage($request);

        $query = StudentDossier::query()
            ->with(['etudiant.faculte'])
            ->withCount('documents')
            ->latest();

        // Périmètre faculté du porteur du token (anti-BOLA §17).
        $faculteRestreinteId = $request->user()?->faculteRestreinteId();

        if ($faculteRestreinteId !== null) {
            $query->whereHas('etudiant', fn ($q) => $q->where('faculte_id', $faculteRestreinteId));
        } elseif ($faculte = trim((string) $request->query('faculty', ''))) {
            $query->whereHas('etudiant.faculte', fn ($q) => $q->where('code', mb_strtoupper($faculte)));
        }

        // ── Filtres AUTORISÉS uniquement (liste blanche §59) ──
        if ($terme = trim((string) $request->query('search', ''))) {
            $motif = Recherche::contient($terme);
            $query->where(function ($q) use ($motif) {
                $q->where('reference', 'like', $motif)
                    ->orWhereHas('etudiant', fn ($e) => $e
                        ->where('nom', 'like', $motif)
                        ->orWhere('prenom', 'like', $motif)
                        ->orWhere('cin', 'like', $motif)
                        ->orWhere('email', 'like', $motif)
                        ->orWhere('num_inscription', 'like', $motif));
            });
        }

        if ($statut = (string) $request->query('status', '')) {
            if (in_array($statut, StudentDossier::STATUTS, true)) {
                $query->where('status', $statut);
            }
        }

        if ($niveau = trim((string) $request->query('niveau', ''))) {
            $query->whereHas('etudiant', fn ($q) => $q->where('niveau', $niveau));
        }

        return StudentDossierResource::collection(
            $query->paginate($perPage)->withQueryString(),
        )->additional([
            'meta' => ['per_page_max' => $this->perPageMax()],
        ]);
    }

    /**
     * Création idempotente du dossier unique d'un étudiant (scope
     * archives:write) : 201 à la création, 200 si le dossier existait —
     * `meta.created` distingue les deux cas. Aucune donnée fournie par le
     * client n'est reprise hors `etudiant_id` (référence et statut serveur).
     */
    public function store(Request $request): JsonResponse
    {
        // Permission vérifiée AVANT toute recherche : un token non habilité
        // reçoit 403 sans pouvoir sonder l'existence d'un étudiant.
        Gate::authorize('create', StudentDossier::class);

        $donnees = $request->validate([
            'etudiant_id' => ['required', 'integer', 'min:1'],
        ]);

        $faculteRestreinteId = $request->user()?->faculteRestreinteId();

        // Étudiant inconnu ET étudiant hors périmètre produisent le même
        // 404 : aucune confirmation d'existence (anti-BOLA §17).
        $etudiant = Etudiant::query()
            ->when($faculteRestreinteId !== null, fn ($q) => $q->where('faculte_id', $faculteRestreinteId))
            ->find($donnees['etudiant_id']);

        abort_if($etudiant === null, 404, 'Étudiant introuvable dans votre périmètre.');

        $dossier = $this->dossiers->obtenirOuCreer($etudiant);
        $cree = (bool) $dossier->wasRecentlyCreated;

        if ($cree) {
            $this->audit->tracer(
                $etudiant,
                HistoriqueAcademique::ACTION_CREATION_DOSSIER,
                'dossier',
                $dossier->id,
                null,
                ['reference' => $dossier->reference, 'status' => $dossier->status],
                'api',
            );
        }

        return (new StudentDossierResource(
            $dossier->loadMissing(['etudiant.faculte'])->loadCount('documents'),
        ))->additional(['meta' => ['created' => $cree]])
            ->response()
            ->setStatusCode($cree ? 201 : 200);
    }

    /**
     * Mise à jour du statut d'un dossier (scope archives:write). Statut
     * inchangé = aucune écriture ni audit ; `etudiant_id` et `reference`
     * ne sont jamais modifiables par l'API.
     */
    public function update(Request $request, StudentDossier $dossier): JsonResponse
    {
        // Hors périmètre = 404 AVANT le contrôle de permission : aucune
        // confirmation d'existence à un token non habilité.
        $this->verifierPerimetre($request, $dossier);

        Gate::authorize('update', $dossier);

        $donnees = $request->validate([
            'status' => ['required', Rule::in(StudentDossier::STATUTS)],
        ]);

        $ancienStatut = $dossier->status;
        $nouveauStatut = (string) $donnees['status'];

        if ($ancienStatut !== $nouveauStatut) {
            $dossier->update(['status' => $nouveauStatut]);

            $this->audit->tracer(
                $dossier->etudiant,
                HistoriqueAcademique::ACTION_MODIFICATION_DOSSIER,
                'dossier',
                $dossier->id,
                ['status' => $ancienStatut],
                ['status' => $nouveauStatut],
                'api',
            );
        }

        return (new StudentDossierResource(
            $dossier->loadMissing(['etudiant.faculte'])->loadCount('documents'),
        ))->response();
    }

    /**
     * Détail d'un dossier (scope archives:read), documents non inclus :
     * leur consultation exige le scope `documents:read` dédié.
     */
    public function show(Request $request, StudentDossier $dossier): StudentDossierResource
    {
        $this->verifierPerimetre($request, $dossier);

        return new StudentDossierResource(
            $dossier->loadMissing(['etudiant.faculte'])->loadCount('documents'),
        );
    }

    /**
     * Métadonnées paginées des documents d'un dossier (scope
     * documents:read) — jamais le chemin de stockage interne.
     */
    public function documents(Request $request, StudentDossier $dossier): AnonymousResourceCollection
    {
        $this->verifierPerimetre($request, $dossier);

        $perPage = $this->parPage($request);

        $query = $dossier->documents()
            ->with('versions')
            ->latest();

        // ── Filtres AUTORISÉS uniquement (liste blanche §59) ──
        if ($statut = (string) $request->query('status', '')) {
            if (in_array($statut, StudentDocument::STATUTS, true)) {
                $query->where('status', $statut);
            }
        }

        $categories = array_keys((array) config('student-dossiers.categories', []));
        if ($categorie = (string) $request->query('category', '')) {
            if (in_array($categorie, $categories, true)) {
                $query->where('category', $categorie);
            }
        }

        if ($type = trim((string) $request->query('document_type', ''))) {
            $query->where('document_type', mb_substr($type, 0, 60));
        }

        if ($terme = trim((string) $request->query('search', ''))) {
            $motif = Recherche::contient($terme);
            $query->where(fn ($q) => $q
                ->where('title', 'like', $motif)
                ->orWhere('original_filename', 'like', $motif));
        }

        return StudentDocumentResource::collection(
            $query->paginate($perPage)->withQueryString(),
        )->additional([
            'meta' => ['per_page_max' => $this->perPageMax()],
        ]);
    }

    /**
     * Anti-BOLA (§17) : 404 hors périmètre, jamais 403 — aucune
     * confirmation d'existence à un porteur de token non habilité.
     */
    private function verifierPerimetre(Request $request, StudentDossier $dossier): void
    {
        $dossier->loadMissing('etudiant');

        $faculteRestreinteId = $request->user()?->faculteRestreinteId();

        if ($faculteRestreinteId !== null
            && (int) $dossier->etudiant->faculte_id !== (int) $faculteRestreinteId) {
            abort(404, 'Ressource introuvable dans votre périmètre.');
        }
    }

    private function parPage(Request $request): int
    {
        return min(
            max(1, (int) $request->query('per_page', 25)),
            $this->perPageMax(),
        );
    }

    private function perPageMax(): int
    {
        return (int) config('integrations.api_per_page_max', 100);
    }
}
