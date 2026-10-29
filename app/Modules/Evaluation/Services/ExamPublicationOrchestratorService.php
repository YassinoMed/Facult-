<?php

namespace App\Modules\Evaluation\Services;

use App\Models\User;
use App\Modules\Evaluation\Events\EvaluationChanged;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Schedule\Services\ExamScheduleEngineService;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Service d'orchestration pour le Menu 10 (Validation, Publication & Scellement)
 *
 * Applique la Règle D-05 (publication officielle immuable, R-27 à R-30),
 * le diagnostic de pré-publication 100 % validé, et l'empreinte cryptographique de scellement.
 */
class ExamPublicationOrchestratorService
{
    public function __construct(
        protected ExamScheduleEngineService $scheduleEngine,
        protected ExamenService $examenService
    ) {}

    /**
     * Audite l'état de publication des examens ordonnancés et publiés.
     *
     * @return array{
     *     examensPrets: LengthAwarePaginator,
     *     statsPublication: array<string, int|float>,
     * }
     */
    public function auditerPublication(
        ?int $anneeId = null,
        ?int $faculteId = null,
        ?string $statutFiltre = null,
        ?string $recherche = null,
        int $perPage = 15
    ): array {
        // Query de base pour le listing paginé
        $query = Examen::query()
            ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
            ->when($anneeId !== null, fn ($q) => $q->where('annee_universitaire_id', $anneeId))
            ->when(
                $statutFiltre !== null && $statutFiltre !== '',
                fn ($q) => $q->where('statut', $statutFiltre),
                fn ($q) => $q->whereIn('statut', ['scheduled', 'published'])
            )
            ->when($recherche !== null && $recherche !== '', fn ($q) => $q->where(fn ($sq) => $sq->where('code', 'like', "%{$recherche}%")
                ->orWhere('libelle', 'like', "%{$recherche}%")
            ))
            ->with([
                'epreuves.salle', 'epreuves.surveillants', 'session', 'programme', 'groupe',
                // Dernière version de gouvernance : la vue n'affiche que
                // des actions réellement permises par le statut courant.
                'validations' => fn ($q) => $q->orderByDesc('version'),
            ])
            ->withCount('epreuves')
            ->orderByDesc('id');

        $examensPrets = $query->paginate($perPage)->withQueryString();

        // Calcul des diagnostics de conformité pour chaque examen affiché
        $nbBloques = 0;
        foreach ($examensPrets->items() as $examen) {
            $diagnostic = $this->diagnostiquerExamen($examen);
            $examen->setAttribute('diagnostic_publication', $diagnostic);
            $examen->setAttribute('empreinte_sha256', $this->calculerEmpreintePublication($examen));
            if ($diagnostic['etat'] === 'bloque_anomalies') {
                $nbBloques++;
            }
        }

        // Statistiques globales de publication pour le périmètre
        $totalScheduled = Examen::query()
            ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
            ->when($anneeId !== null, fn ($q) => $q->where('annee_universitaire_id', $anneeId))
            ->where('statut', 'scheduled')
            ->count();

        $totalPublished = Examen::query()
            ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
            ->when($anneeId !== null, fn ($q) => $q->where('annee_universitaire_id', $anneeId))
            ->where('statut', 'published')
            ->count();

        $epreuvesScheduled = Epreuve::query()
            ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
            ->when($anneeId !== null, fn ($q) => $q->where('annee_universitaire_id', $anneeId))
            ->whereHas('examen', fn ($q) => $q->where('statut', 'scheduled'))
            ->count();

        $epreuvesPublished = Epreuve::query()
            ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
            ->when($anneeId !== null, fn ($q) => $q->where('annee_universitaire_id', $anneeId))
            ->whereHas('examen', fn ($q) => $q->where('statut', 'published'))
            ->count();

        $totalExamens = $totalScheduled + $totalPublished;
        $tauxPublication = $totalExamens > 0 ? round(($totalPublished / $totalExamens) * 100, 1) : 0.0;

        $statsPublication = [
            'scheduled' => $totalScheduled,
            'published' => $totalPublished,
            'epreuves_scheduled' => $epreuvesScheduled,
            'epreuves_published' => $epreuvesPublished,
            'bloquants_visibles' => $nbBloques,
            'taux_publication' => $tauxPublication,
        ];

        return [
            'examensPrets' => $examensPrets,
            'statsPublication' => $statsPublication,
        ];
    }

    /**
     * Diagnostique l'état de pré-publication d'un examen spécifique.
     *
     * @return array{
     *     etat: string,
     *     conforme: bool,
     *     score: int,
     *     conflits: array<string>,
     *     nb_epreuves: int,
     *     date_publication: ?Carbon
     * }
     */
    public function diagnostiquerExamen(Examen $examen): array
    {
        if ($examen->statut === 'published') {
            return [
                'etat' => 'deja_publie',
                'conforme' => true,
                'score' => 100,
                'conflits' => [],
                'nb_epreuves' => $examen->epreuves_count ?? $examen->epreuves()->count(),
                'date_publication' => $examen->published_at,
            ];
        }

        $audit = $this->scheduleEngine->auditerSessionExamens($examen);
        $conforme = (bool) ($audit['conforme'] ?? false);
        $conflits = $audit['conflits_bloquants'] ?? [];
        $nbEpreuves = (int) ($audit['nb_epreuves'] ?? ($examen->epreuves_count ?? $examen->epreuves()->count()));

        $score = $conforme ? 100 : (int) ($audit['score_conformite'] ?? max(0, 100 - (count($conflits) * 20)));

        return [
            'etat' => $conforme ? 'pret_a_publier' : 'bloque_anomalies',
            'conforme' => $conforme,
            'score' => $score,
            'conflits' => $conflits,
            'nb_epreuves' => $nbEpreuves,
            'date_publication' => $examen->published_at,
        ];
    }

    /**
     * Calcule une empreinte cryptographique SHA-256 déterministe basée sur le planning complet.
     * Garantit le scellement officiel et la détection d'altération (R-30).
     */
    public function calculerEmpreintePublication(Examen $examen): string
    {
        $examen->loadMissing(['epreuves.salle', 'epreuves.surveillants', 'epreuves.matiere']);

        $donneesPlan = [
            'examen_id' => $examen->id,
            'code' => $examen->code,
            'session_id' => $examen->session_id,
            'annee_id' => $examen->annee_universitaire_id,
            'programme_id' => $examen->programme_id,
            'epreuves' => $examen->epreuves
                ->sortBy(fn ($e) => ($e->date?->format('Y-m-d') ?? '').'_'.$e->heure_debut.'_'.$e->id)
                ->map(fn (Epreuve $e) => [
                    'code' => $e->code,
                    'date' => $e->date?->format('Y-m-d'),
                    'heure_debut' => (string) $e->heure_debut,
                    'heure_fin' => (string) $e->heure_fin,
                    'salle_id' => $e->salle_id,
                    'matiere_id' => $e->matiere_id,
                    'groupe_id' => $e->groupe_id,
                    'population_hash' => $e->population_hash,
                    'coefficient' => (string) $e->coefficient,
                    'bareme' => (string) $e->bareme,
                    'surveillants_count' => $e->surveillants->where('statut', '!=', 'annule')->count(),
                ])->values()->all(),
        ];

        return hash('sha256', (string) json_encode($donneesPlan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Publie et scelle officiellement un examen conforme (Règle D-05).
     *
     * @throws DomainException Si l'audit de conformité échoue
     */
    public function publierExamen(Examen $examen, int $userId): Examen
    {
        // Défense en profondeur : même appelée hors HTTP, la publication
        // exige explicitement la permission dédiée. Le contrôleur ne peut
        // donc pas devenir un contournement par oubli de Gate.
        $acteur = User::find($userId);
        if ($acteur === null || ! $acteur->hasPermission('evaluations.publish')) {
            throw new DomainException('Publication refusée : l’acteur doit détenir la permission evaluations.publish.');
        }

        return DB::transaction(function () use ($examen, $userId) {
            // Verrou de ligne : deux publications concurrentes ne peuvent
            // pas sceller deux fois le même examen.
            /** @var Examen $examen */
            $examen = Examen::whereKey($examen->id)->lockForUpdate()->firstOrFail();

            // Vérification de la readiness
            $diagnostic = $this->diagnostiquerExamen($examen);
            if (! $diagnostic['conforme']) {
                $erreurs = implode(' ; ', $diagnostic['conflits']);
                throw new DomainException("Publication impossible : contraintes bloquantes non résolues ({$erreurs}).");
            }

            // Transition de l'examen via ExamenService. Un examen DÉJÀ
            // publié qui reçoit une nouvelle version reste publié : on
            // scelle la nouvelle version sans rejouer la transition
            // (published → published n'existe pas dans la matrice).
            $examenPublie = $examen->statut === 'published'
                ? tap($examen)->update(['published_at' => now()])
                : $this->examenService->transiter($examen, 'published');

            // Mise à jour synchrone des épreuves vers 'published' pour débloquer les convocations (D-06)
            Epreuve::where('examen_id', $examen->id)
                ->where('statut', 'scheduled')
                ->update(['statut' => 'published']);

            // Événement d'audit de scellement
            EvaluationChanged::dispatch('examen.scellement_publication', $examenPublie->id, [
                'user_id' => $userId,
                'empreinte_sha256' => $this->calculerEmpreintePublication($examenPublie),
                'published_at' => $examenPublie->published_at?->toIso8601String(),
            ]);

            return $examenPublie->fresh(['epreuves']);
        });
    }
}
