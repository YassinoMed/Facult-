<?php

namespace App\Modules\StudentDossiers\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\StudentDossiers\Models\StudentDocument;
use App\Modules\StudentDossiers\Models\StudentDossier;
use App\Modules\StudentDossiers\Resources\StudentDocumentResource;
use App\Modules\StudentDossiers\Resources\StudentDossierResource;
use App\Support\Recherche;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * API v1 — dossiers administratifs permanents (module StudentDossiers).
 *
 * Lecture seule (§16) : les Resources exposent des métadonnées, jamais
 * `storage_path` ni le contenu des fichiers. Chaque route porte son
 * scope Sanctum + throttle ; les filtres passent par une liste blanche
 * et la pagination est bornée (§58-59).
 *
 * Isolation par faculté (§17) : un admin_faculte ne voit que les
 * dossiers des étudiants de sa faculté — vérifié en base à chaque
 * requête, avec un 404 (jamais 403) hors périmètre.
 */
class StudentDossierApiController extends Controller
{
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
