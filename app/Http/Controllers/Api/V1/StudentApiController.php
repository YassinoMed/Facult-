<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AcademicRecordResource;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\GradeResource;
use App\Http\Resources\StudentResource;
use App\Models\Etudiant;
use App\Models\HistoriqueAcademique;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Services\CalculEvaluationService;
use App\Modules\StudentArchive\Services\ArchiveTimelineRegistry;
use App\Services\AuditService;
use App\Services\Verification\VerificationEngine;
use App\Services\Verification\VerificationStore;
use App\Support\Recherche;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * API v1 — étudiants (§44-45).
 * Chaque route porte son scope + throttle ; les filtres passent par une
 * liste blanche (§59) ; la pagination est bornée (§58).
 *
 * Isolation par faculté (§17) : authentification ≠ autorisation. Même
 * avec un token valide, un admin_faculte n'obtient QUE les données de
 * sa faculté — le périmètre est relu en base à chaque requête.
 */
class StudentApiController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(
            (int) $request->query('per_page', 25) ?: 25,
            (int) config('integrations.api_per_page_max', 100),
        );
        $perPage = max(1, $perPage);

        $query = Etudiant::query()->with('faculte')->orderBy('nom');

        // Périmètre faculté du porteur du token (anti-BOLA §17)
        $faculteRestreinteId = $request->user()?->faculteRestreinteId();
        if ($faculteRestreinteId !== null) {
            $query->where('faculte_id', $faculteRestreinteId);
        }

        // ── Filtres AUTORISÉS uniquement (liste blanche §59) ──
        // Jokers LIKE neutralisés : une recherche reste littérale.
        if ($terme = trim((string) $request->query('search', ''))) {
            $motif = Recherche::contient($terme);
            $query->where(fn ($q) => $q
                ->where('nom', 'like', $motif)
                ->orWhere('prenom', 'like', $motif)
                ->orWhere('cin', 'like', $motif)
                ->orWhere('num_inscription', 'like', $motif));
        }

        if ($faculteRestreinteId === null && $faculte = (string) $request->query('faculty', '')) {
            $query->whereHas('faculte', fn ($q) => $q->where('code', mb_strtoupper($faculte)));
        }

        if ($statut = (string) $request->query('status', '')) {
            $query->where('statut', $statut);
        }

        if ($niveau = (string) $request->query('niveau', '')) {
            $query->where('niveau', $niveau);
        }

        return StudentResource::collection(
            $query->paginate($perPage)->withQueryString(),
        )->additional([
            'meta' => ['per_page_max' => (int) config('integrations.api_per_page_max', 100)],
        ]);
    }

    public function show(Etudiant $student): StudentResource
    {
        $this->verifierPerimetreFaculte($student);

        return new StudentResource($student->load('faculte'));
    }

    /**
     * Dossier académique complet (scope archives:read).
     */
    public function academicRecord(Etudiant $student)
    {
        $this->verifierPerimetreFaculte($student);

        $dossier = $student->dossierAcademique()->with([
            'etudiant.faculte',
            'inscriptions' => fn ($q) => $q->with([
                'anneeUniversitaire',
                'semestres.notes.matiere',
            ])->orderByDesc('annee_universitaire_id'),
        ])->first();

        abort_if($dossier === null, 404, 'Aucun dossier académique pour cet étudiant.');

        return new AcademicRecordResource($dossier);
    }

    /**
     * Notes de l'étudiant (scope grades:read), paginées.
     */
    public function grades(Request $request, Etudiant $student): AnonymousResourceCollection
    {
        $this->verifierPerimetreFaculte($student);

        $perPage = min(max(1, (int) $request->query('per_page', 50)), (int) config('integrations.api_per_page_max', 100));

        $notes = $student->notesAcademiques()
            ->with(['matiere', 'semestre'])
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return GradeResource::collection($notes);
    }

    /**
     * MÉTADONNÉES des documents (scope documents:read) — jamais le chemin.
     * Paginées (§20.7) : jamais de collection complète.
     */
    public function documents(Request $request, Etudiant $student): AnonymousResourceCollection
    {
        $this->verifierPerimetreFaculte($student);

        $perPage = min(max(1, (int) $request->query('per_page', 50)), (int) config('integrations.api_per_page_max', 100));

        return DocumentResource::collection(
            $student->documentsAcademiques()->with('anneeUniversitaire')->latest()->paginate($perPage),
        );
    }

    /**
     * Résultats académiques par inscription (Phase 6 §38, scope
     * grades:read) : synthèse moteur (moyenne, crédits, dettes UE) +
     * décision publiée si elle existe. Calcul déterministe, jamais
     * de PII au-delà de l'identité déjà exposée par students.show.
     */
    public function results(Request $request, Etudiant $student): JsonResponse
    {
        $this->verifierPerimetreFaculte($student);

        $calculs = app(CalculEvaluationService::class);

        $inscriptions = $student->inscriptionsAcademiques()->with('anneeUniversitaire')->orderByDesc('annee_universitaire_id')->get();

        $lignes = $inscriptions->map(function ($inscription) use ($calculs, $student) {
            $decision = DecisionDeliberation::where('inscription_academique_id', $inscription->id)
                ->where('publie', true)->latest('id')->first();

            // Phase 14 §14.7 : sans décision publiée, aucun résultat
            // calculé n'est exposé (moyenne et proposition à null).
            if ($decision === null) {
                return [
                    'inscription_id' => $inscription->id,
                    'annee' => $inscription->anneeUniversitaire?->libelle,
                    'niveau' => $inscription->niveau,
                    'moyenne' => null,
                    'credits' => [0, 0],
                    'dettes' => [],
                    'decision_proposee' => null,
                    'decision' => null,
                ];
            }

            $synthese = $calculs->syntheseEtudiant($student, (int) $inscription->annee_universitaire_id, $inscription->programme_id);

            return [
                'inscription_id' => $inscription->id,
                'annee' => $inscription->anneeUniversitaire?->libelle,
                'niveau' => $inscription->niveau,
                'moyenne' => $synthese['moyenne'],
                'credits' => [$synthese['credits_obtenus'], $synthese['credits_total']],
                'dettes' => collect($synthese['detail_ues'])
                    ->reject(fn ($ue) => $ue['validee'])
                    ->map(fn ($ue) => $ue['ue_id'])->values()->all(),
                'decision_proposee' => $synthese['decision_proposee'],
                'decision' => $decision->decision,
            ];
        })->values()->all();

        return response()->json(['data' => $lignes]);
    }

    /**
     * Timeline académique (Phase 8 §9, scope archives:read) : registre
     * d'archive (8 sources, jamais de seconde source), paginée par
     * offset — jamais d'histoire complète en une requête (§108).
     */
    public function timeline(Request $request, Etudiant $student): JsonResponse
    {
        $this->verifierPerimetreFaculte($student);

        $limite = max(1, min(100, (int) $request->query('limite', 20)));
        $offset = max(0, (int) $request->query('offset', 0));

        $resultat = app(ArchiveTimelineRegistry::class)->evenements($student, [
            'categorie' => $request->query('categorie'),
            'limite' => $limite,
            'offset' => $offset,
        ]);

        return response()->json([
            'data' => $resultat['elements']->map(fn (array $e) => [
                'date' => $e['date'] instanceof \DateTimeInterface ? $e['date']->format('Y-m-d') : (string) ($e['date'] ?? ''),
                'categorie' => $e['categorie'],
                'titre' => $e['titre'],
                'detail' => $e['detail'],
            ])->values()->all(),
            'meta' => ['total' => $resultat['total'], 'limite' => $limite, 'offset' => $offset],
        ]);
    }

    /**
     * Rapport de vérification intelligente (§21).
     */
    public function verification(Etudiant $student, VerificationEngine $engine): JsonResponse
    {
        $this->verifierPerimetreFaculte($student);

        $resultat = $engine->verifier($student);

        return response()->json([
            'data' => $resultat->toArray(),
        ]);
    }

    /**
     * Déclenchement d'une vérification avec audit (§21).
     */
    public function runVerification(Etudiant $student, VerificationEngine $engine, AuditService $audit, VerificationStore $runs): JsonResponse
    {
        $this->verifierPerimetreFaculte($student);

        $resultat = $engine->verifier($student);
        $run = $runs->enregistrer($student, $resultat, 'api', null);

        $audit->tracer(
            $student,
            HistoriqueAcademique::ACTION_VERIFICATION_DOSSIER,
            'etudiant',
            $student->id,
            null,
            [
                'verdict' => $resultat->verdict,
                'score' => $resultat->score,
                'anomalies_count' => count($resultat->anomalies),
                'duree_ms' => $resultat->dureeMs,
            ],
            'api'
        );

        return response()->json([
            'message' => 'Vérification exécutée.',
            'verification_id' => $run->id,
            'data' => $resultat->toArray(),
        ]);
    }

    /**
     * Liste des anomalies uniquement (§21).
     */
    public function anomalies(Etudiant $student, VerificationEngine $engine): JsonResponse
    {
        $this->verifierPerimetreFaculte($student);

        $resultat = $engine->verifier($student);

        return response()->json([
            'data' => array_map(fn ($a) => [
                'code' => $a->regle,
                'categorie' => $a->categorie,
                'gravite' => $a->gravite,
                'message' => $a->message,
                'recommandation' => $a->recommandation,
            ], $resultat->anomalies),
            'meta' => [
                'total' => count($resultat->anomalies),
                'verdict' => $resultat->verdict,
                'score' => $resultat->score,
            ],
        ]);
    }

    /**
     * Anti-BOLA (§17) : la ressource ciblée doit appartenir au périmètre
     * de faculté du porteur du token — vérifié en base, jamais côté client.
     * 404 (et non 403) : aucune confirmation d'existence hors périmètre.
     */
    private function verifierPerimetreFaculte(Etudiant $student): void
    {
        $faculteRestreinteId = request()->user()?->faculteRestreinteId();

        if ($faculteRestreinteId !== null && (int) $student->faculte_id !== $faculteRestreinteId) {
            abort(404, 'Ressource introuvable dans votre périmètre.');
        }
    }
}
