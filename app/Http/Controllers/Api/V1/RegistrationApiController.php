<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\InscriptionAcademiqueResource;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Services\PeriodeInscriptionService;
use App\Services\ReinscriptionService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * API v1 — inscriptions (Phase 3, lecture seule).
 * Mêmes conventions que StudentApiController : scope archives:read,
 * filtres whitelistés, pagination bornée, périmètre faculté relu en base.
 * Pas d'écriture (surface minimale volontaire).
 */
class RegistrationApiController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = max(1, min(
            (int) $request->query('per_page', 25) ?: 25,
            (int) config('integrations.api_per_page_max', 100),
        ));

        $query = InscriptionAcademique::query()
            ->with(['anneeUniversitaire', 'programmeRef', 'niveauRef'])
            ->orderByDesc('annee_universitaire_id');

        if ($request->user()?->faculteRestreinteId() !== null) {
            $query->whereHas('etudiant', fn ($q) => $q->where('faculte_id', $request->user()->faculteRestreinteId()));
        }

        if ($annee = (string) $request->query('annee', '')) {
            $query->whereHas('anneeUniversitaire', fn ($q) => $q->where('libelle', $annee));
        }

        if ($programme = (string) $request->query('programme', '')) {
            $query->whereHas('programmeRef', fn ($q) => $q->where('code', mb_strtoupper($programme)));
        }

        if ($niveau = (string) $request->query('niveau', '')) {
            $query->where('niveau', $niveau);
        }

        if ($statut = (string) $request->query('statut', '')) {
            $query->where('statut', $statut);
        }

        if ($etudiant = (string) $request->query('etudiant', '')) {
            $query->where('etudiant_id', (int) $etudiant);
        }

        return InscriptionAcademiqueResource::collection($query->paginate($perPage));
    }

    public function show(Request $request, InscriptionAcademique $registration): InscriptionAcademiqueResource
    {
        $faculteRestreinteId = $request->user()?->faculteRestreinteId();

        if ($faculteRestreinteId !== null && (int) $registration->etudiant->faculte_id !== $faculteRestreinteId) {
            abort(404);
        }

        return new InscriptionAcademiqueResource($registration->loadMissing([
            'anneeUniversitaire', 'programmeRef', 'niveauRef', 'semestres',
        ]));
    }

    /**
     * Contexte de réinscription (lecture, scope archives:read) :
     * existence, dernière inscription, niveau proposé + source,
     * pièces — `proposed_level` n'est jamais une décision officielle.
     */
    public function reenrollmentContext(Request $request, Etudiant $student, ReinscriptionService $reinscriptions): JsonResponse
    {
        $this->verifierPerimetre($request, $student);

        $annee = $request->integer('annee_universitaire_id') > 0
            ? AnneeUniversitaire::findOrFail($request->integer('annee_universitaire_id'))
            : (AnneeUniversitaire::active() ?? AnneeUniversitaire::orderByDesc('libelle')->firstOrFail());

        $contexte = $reinscriptions->contexte($student, $annee);

        return response()->json(['data' => [
            'student_exists' => true,
            'student_id' => $student->id,
            'last_registration' => $contexte['derniere'] ? new InscriptionAcademiqueResource($contexte['derniere']) : null,
            'annee_cible' => $annee->libelle,
            'inscription_existante' => $contexte['existante'] !== null,
            'situation' => $contexte['situation'],
            'proposed_level' => $contexte['niveau_propose']['code'] ?? null,
            'progression_status' => $contexte['evaluation']['peut_progresser'] ?? false ? 'ELIGIBLE' : 'BLOQUE',
            'decision_source' => $contexte['source_decision'],
            'blocages' => $contexte['evaluation']['blocages'] ?? [],
            'required_documents' => config('inscriptions.documents_requis', []),
            'missing_documents' => $contexte['documents_manquants'],
        ]]);
    }

    /**
     * Progression académique dry-run (lecture, scope archives:read).
     */
    public function progression(Request $request, Etudiant $student, ReinscriptionService $reinscriptions): JsonResponse
    {
        $this->verifierPerimetre($request, $student);

        $derniere = $student->inscriptionsAcademiques()->latest('annee_universitaire_id')->firstOrFail();
        $anneeCible = $derniere->anneeUniversitaire?->suivante()
            ?? AnneeUniversitaire::active()
            ?? AnneeUniversitaire::orderByDesc('libelle')->firstOrFail();
        $contexte = $reinscriptions->contexte($student, $anneeCible);

        return response()->json(['data' => [
            'situation' => $contexte['situation'],
            'niveau_propose' => $contexte['niveau_propose'],
            'decision_source' => $contexte['source_decision'],
            'blocages' => $contexte['evaluation']['blocages'] ?? [],
        ]]);
    }

    /**
     * Réinscription (écriture, scope registrations:write + throttle +
     * idempotence HTTP). Retourne l'existante en cas de retry.
     */
    public function reenroll(Request $request, Etudiant $student, ReinscriptionService $reinscriptions): JsonResponse
    {
        $this->verifierPerimetre($request, $student);

        $donnees = $request->validate([
            'annee_universitaire_id' => ['required', 'integer', 'exists:annees_universitaires,id'],
            'programme_id' => ['nullable', 'integer', 'exists:programmes,id'],
            'niveau_id' => ['nullable', 'integer', 'exists:niveaux,id'],
            'regime' => ['nullable', 'in:initiale,continue,alternance'],
            'confirmer' => ['required', 'accepted'],
        ]);

        try {
            $inscription = $reinscriptions->confirmer(
                $student,
                AnneeUniversitaire::findOrFail($donnees['annee_universitaire_id']),
                [
                    'programme_id' => $donnees['programme_id'] ?? null,
                    'niveau_id' => $donnees['niveau_id'] ?? null,
                    'regime' => $donnees['regime'] ?? 'initiale',
                    'confirmer' => true,
                ],
                (int) $request->user()->id
            );
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => new InscriptionAcademiqueResource($inscription)], 201);
    }

    /**
     * État période d'inscription (lecture, scope archives:read) : même
     * source que la page et le POST (PeriodeInscriptionService).
     */
    public function periodeStatut(Request $request, Etudiant $student, PeriodeInscriptionService $periodes): JsonResponse
    {
        $this->verifierPerimetre($request, $student);

        $annee = $request->integer('annee_universitaire_id') > 0
            ? AnneeUniversitaire::findOrFail($request->integer('annee_universitaire_id'))
            : (AnneeUniversitaire::active() ?? AnneeUniversitaire::orderByDesc('libelle')->firstOrFail());

        $etat = $periodes->etat($student, $annee, $request->query('type_inscription'));

        return response()->json(['data' => [
            'status' => $etat['statut'],
            'is_open' => $etat['interactif'],
            'starts_at' => $etat['debut'],
            'ends_at' => $etat['fin'],
            'exception' => $etat['statut'] === 'EXCEPTION_OPEN',
            'message' => $etat['message'],
        ]]);
    }

    private function verifierPerimetre(Request $request, Etudiant $student): void
    {
        $faculteRestreinteId = $request->user()?->faculteRestreinteId();

        if ($faculteRestreinteId !== null && (int) $student->faculte_id !== $faculteRestreinteId) {
            abort(404);
        }
    }
}
