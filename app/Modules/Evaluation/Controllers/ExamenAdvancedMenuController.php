<?php

namespace App\Modules\Evaluation\Controllers;

use App\Models\AnneeUniversitaire;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\DocumentExamenGenere;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Models\Surveillance;
use App\Modules\Evaluation\Services\ConvocationService;
use App\Modules\Evaluation\Services\ExamCandidatsConvocationsOrchestratorService;
use App\Modules\Evaluation\Services\ExamDashboardOrchestratorService;
use App\Modules\Evaluation\Services\ExamDecisionCapacitePermutationService;
use App\Modules\Evaluation\Services\ExamHistoriqueAuditOrchestratorService;
use App\Modules\Evaluation\Services\ExamPlanificationSallesOrchestratorService;
use App\Modules\Evaluation\Services\ExamPublicationOrchestratorService;
use App\Modules\Evaluation\Services\ExamSessionConfigurationService;
use App\Modules\Evaluation\Services\ExamSurveillanceGroupesOrchestratorService;
use App\Modules\Evaluation\Services\PreparationExamenService;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Teacher;
use App\Modules\Schedule\Services\ExamScheduleEngineService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Contrôleur des 12 menus fonctionnels d'Examens Advanced (LOOP MASTER).
 *
 * Centralise la navigation et la consultation des 12 surfaces spécialisées
 * sans dupliquer la logique métier, en déléguant aux services centraux
 * (PreparationExamenService, ExamScheduleEngineService, ConvocationService).
 */
class ExamenAdvancedMenuController
{
    public function __construct(
        private PreparationExamenService $preparation,
        private ExamScheduleEngineService $scheduleEngine,
        private ConvocationService $convocationService,
        private ExamDashboardOrchestratorService $orchestrator,
        private ExamSessionConfigurationService $sessionConfigService,
        private ExamPlanificationSallesOrchestratorService $planificationSallesOrchestrator,
        private ExamCandidatsConvocationsOrchestratorService $candidatsConvocationsOrchestrator,
        private ExamSurveillanceGroupesOrchestratorService $surveillanceGroupesOrchestrator,
        private ExamPublicationOrchestratorService $publicationOrchestrator,
        private ExamHistoriqueAuditOrchestratorService $historiqueAuditOrchestrator,
        private ExamDecisionCapacitePermutationService $decisionCapacite,
    ) {}

    /**
     * Variables communes à toutes les vues Examens Advanced (KPI bandeau).
     * Évite la duplication dans chaque méthode.
     */
    private function commonExamensAdvancedVars(?int $anneeId, ?int $faculteId): array
    {
        $statsPreparation = $this->preparation->tableauDeBord($anneeId, $faculteId);

        $sessionsStats = [
            'total' => SessionExamen::query()->when($anneeId !== null, fn ($q) => $q->where('annee_universitaire_id', $anneeId))->count(),
            'draft' => SessionExamen::where('statut', 'draft')->count(),
            'open' => SessionExamen::where('statut', 'open')->count(),
            'ongoing' => SessionExamen::where('statut', 'ongoing')->count(),
            'closed' => SessionExamen::where('statut', 'closed')->count(),
            'locked' => SessionExamen::where('statut', 'locked')->count(),
        ];

        // Salles sans capacité validée
        $sallesSansCapacite = Room::where(fn ($q) => $q->whereNull('capacite')->orWhere('capacite', '<=', 0))->count();

        // Épreuves sans surveillants requis
        $epreuvesSansSurveillant = Epreuve::query()
            ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
            ->whereDoesntHave('surveillants')
            ->count();

        return [
            'statsPreparation' => $statsPreparation,
            'sessionsStats' => $sessionsStats,
            'sallesSansCapacite' => $sallesSansCapacite,
            'epreuvesSansSurveillant' => $epreuvesSansSurveillant,
        ];
    }

    /**
     * Menu 1 — Tableau de bord des examens
     * Route : /admin/evaluation/examens/tableau-de-bord
     */
    public function tableauDeBord(Request $request): View
    {
        Gate::authorize('evaluations.view');

        $faculteId = $request->user()->faculteRestreinteId();
        $anneeId = $request->filled('annee_id') ? (int) $request->input('annee_id') : null;

        $donneesEnterprise = $this->orchestrator->obtenirTableauDeBordEnterprise($anneeId, $faculteId, $request->user());

        $statsPreparation = $this->preparation->tableauDeBord($donneesEnterprise['annee_id'], $faculteId);
        $sessionsStats = $donneesEnterprise['kpis']['sessions'];
        $examensStats = [
            'total' => $donneesEnterprise['kpis']['examens']['total'],
            'published' => $donneesEnterprise['kpis']['examens']['publies'],
            'draft' => Examen::query()->when($donneesEnterprise['annee_id'] !== null, fn ($q) => $q->where('annee_universitaire_id', $donneesEnterprise['annee_id']))->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))->where('statut', 'draft')->count(),
            'scheduled' => Examen::query()->when($donneesEnterprise['annee_id'] !== null, fn ($q) => $q->where('annee_universitaire_id', $donneesEnterprise['annee_id']))->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))->where('statut', 'scheduled')->count(),
            'planned' => Examen::query()->when($donneesEnterprise['annee_id'] !== null, fn ($q) => $q->where('annee_universitaire_id', $donneesEnterprise['annee_id']))->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))->where('statut', 'planned')->count(),
            'ongoing' => Examen::query()->when($donneesEnterprise['annee_id'] !== null, fn ($q) => $q->where('annee_universitaire_id', $donneesEnterprise['annee_id']))->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))->where('statut', 'ongoing')->count(),
            'completed' => Examen::query()->when($donneesEnterprise['annee_id'] !== null, fn ($q) => $q->where('annee_universitaire_id', $donneesEnterprise['annee_id']))->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))->where('statut', 'completed')->count(),
        ];
        $sallesSansCapacite = $donneesEnterprise['kpis']['salles']['sans_capacite'];
        $epreuvesSansSurveillant = $donneesEnterprise['kpis']['epreuves']['sans_surveillant'];

        return view('modules.evaluation.examens-advanced.tableau-de-bord', array_merge($donneesEnterprise, [
            'statsPreparation' => $statsPreparation,
            'sessionsStats' => $sessionsStats,
            'examensStats' => $examensStats,
            'sallesSansCapacite' => $sallesSansCapacite,
            'epreuvesSansSurveillant' => $epreuvesSansSurveillant,
            'anneeId' => $donneesEnterprise['annee_id'],
        ]));
    }

    /**
     * Menu 2 — Sessions d'examen
     * Route : /admin/evaluation/examens/sessions
     */
    public function sessions(Request $request): View
    {
        Gate::authorize('sessions_examen.view');

        $faculteId = $request->user()->faculteRestreinteId();
        $anneeId = $request->filled('annee_id') ? (int) $request->input('annee_id') : null;
        $statut = $request->input('statut');

        $sessionsQuery = SessionExamen::query()
            ->with(['anneeUniversitaire', 'origine'])
            ->withCount(['examens', 'epreuves'])
            ->when($anneeId !== null, fn ($q) => $q->where('annee_universitaire_id', $anneeId))
            ->when($statut !== null && $statut !== '', fn ($q) => $q->where('statut', $statut))
            ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
            ->orderByDesc('date_debut');

        $sessions = $sessionsQuery->paginate(15)->withQueryString();

        $auditsSessions = [];
        foreach ($sessions->items() as $sess) {
            $auditsSessions[$sess->id] = $this->sessionConfigService->auditerSession($sess);
        }

        $annees = AnneeUniversitaire::orderByDesc('date_debut')->get();

        $commun = $this->commonExamensAdvancedVars($anneeId, $faculteId);

        return view('modules.evaluation.examens-advanced.sessions', [
            'sessions' => $sessions,
            'auditsSessions' => $auditsSessions,
            'annees' => $annees,
            'statuts' => SessionExamen::STATUTS,
            'anneeId' => $anneeId,
            'statutSelectionne' => $statut,
        ] + $commun);
    }

    /**
     * Menu 3 — Configurations d'examen et modules
     * Route : /admin/evaluation/examens/configurations
     */
    public function configurations(Request $request): View
    {
        Gate::authorize('evaluations.view');

        $faculteId = $request->user()->faculteRestreinteId();
        $anneeId = $request->filled('annee_id') ? (int) $request->input('annee_id') : null;
        $sessionId = $request->filled('session_id') ? (int) $request->input('session_id') : null;

        $examens = Examen::query()
            ->with(['session', 'programme', 'niveau', 'groupe'])
            ->withCount('epreuves')
            ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
            ->when($sessionId !== null, fn ($q) => $q->where('session_id', $sessionId))
            ->when($anneeId !== null, fn ($q) => $q->where('annee_universitaire_id', $anneeId))
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        $sessions = SessionExamen::query()
            ->when($anneeId !== null, fn ($q) => $q->where('annee_universitaire_id', $anneeId))
            ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
            ->orderByDesc('date_debut')
            ->get();

        $auditConfigurations = $this->sessionConfigService->auditerConfigurations($sessionId, $faculteId, $anneeId);

        $annees = AnneeUniversitaire::orderByDesc('date_debut')->get();
        $commun = $this->commonExamensAdvancedVars($anneeId, $faculteId);

        return view('modules.evaluation.examens-advanced.configurations', [
            'examens' => $examens,
            'sessions' => $sessions,
            'sessionId' => $sessionId,
            'anneeId' => $anneeId,
            'annees' => $annees,
            'auditConfigurations' => $auditConfigurations,
        ] + $commun);
    }

    /**
     * Menu 4 — Éligibilité des candidats
     * Route : /admin/evaluation/examens/eligibilite
     */
    public function eligibilite(Request $request): View
    {
        Gate::authorize('evaluations.view');

        $faculteId = $request->user()->faculteRestreinteId();
        $anneeId = $request->filled('annee_id') ? (int) $request->input('annee_id') : null;
        $epreuveId = $request->filled('epreuve_id') ? (int) $request->input('epreuve_id') : null;

        $epreuve = null;
        $candidats = collect();
        $auditEligibilite = null;

        if ($epreuveId !== null) {
            $epreuve = Epreuve::query()
                ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
                ->with(['matiere', 'salle', 'session', 'groupe'])
                ->find($epreuveId);

            if ($epreuve !== null) {
                $auditEligibilite = $this->candidatsConvocationsOrchestrator->auditerEligibiliteEpreuve($epreuve);
                $candidats = Candidat::where('epreuve_id', $epreuve->id)
                    ->with(['etudiant.groupe', 'inscriptionPedagogique', 'salle'])
                    ->paginate(25)
                    ->withQueryString();
            }
        }

        $epreuves = Epreuve::query()
            ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
            ->with('matiere')
            ->orderByDesc('date')
            ->limit(50)
            ->get();

        $commun = $this->commonExamensAdvancedVars($anneeId, $faculteId);

        return view('modules.evaluation.examens-advanced.eligibilite', [
            'epreuveSelectionnee' => $epreuve,
            'candidats' => $candidats,
            'auditEligibilite' => $auditEligibilite,
            'epreuves' => $epreuves,
            'epreuveId' => $epreuveId,
        ] + $commun);
    }

    /**
     * Menu 5 — Groupes d'examen
     * Route : /admin/evaluation/examens/groupes
     */
    public function groupes(Request $request): View
    {
        Gate::authorize('epreuves.view');

        $faculteId = $request->user()->faculteRestreinteId();
        $anneeId = $request->filled('annee_id') ? (int) $request->input('annee_id') : null;
        $recherche = $request->input('recherche');
        $examenId = $request->filled('examen_id') ? (int) $request->input('examen_id') : null;
        $sessionId = $request->filled('session_id') ? (int) $request->input('session_id') : null;
        $epreuveId = $request->filled('epreuve_id') ? (int) $request->input('epreuve_id') : null;
        $mode = strtoupper((string) $request->input('mode', 'A'));
        if (! in_array($mode, ['A', 'B'], true)) {
            $mode = 'A';
        }

        $kpisGroupes = $this->surveillanceGroupesOrchestrator->auditerGroupes($faculteId, $recherche);
        $donneesModeB = $this->surveillanceGroupesOrchestrator->auditerModeBAmphitheatres($faculteId, $recherche);

        $groupes = Group::query()
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->when($recherche !== null && $recherche !== '', function ($q) use ($recherche) {
                $q->where(fn ($sq) => $sq->where('code', 'like', "%{$recherche}%")->orWhere('nom', 'like', "%{$recherche}%"));
            })
            ->withCount(['etudiants'])
            ->orderBy('code')
            ->paginate(20)
            ->withQueryString();

        // Décision D-04 : capacité et permutations (uniquement Mode B, lecture seule).
        $capacitePayload = null;
        if ($mode === 'B') {
            $capacitePayload = $this->decisionCapacite->payloadVue(
                $anneeId,
                $faculteId,
                $sessionId,
                $examenId,
                $epreuveId,
                $request->user()?->id,
            );
        }

        $commun = $this->commonExamensAdvancedVars($anneeId, $faculteId);

        return view('modules.evaluation.examens-advanced.groupes', [
            'mode' => $mode,
            'groupes' => $groupes,
            'kpisGroupes' => $kpisGroupes,
            'donneesModeB' => $donneesModeB,
            'recherche' => $recherche,
            'examen_id' => $examenId,
            'session_id' => $sessionId,
            'epreuve_id' => $epreuveId,
            'capacite' => $capacitePayload,
        ] + $commun);
    }

    /**
     * Menu 6 — Salles et capacités
     * Route : /admin/evaluation/examens/salles
     */
    public function salles(Request $request): View
    {
        Gate::authorize('evaluations.view');

        $faculteId = $request->user()->faculteRestreinteId();
        $anneeId = $request->filled('annee_id') ? (int) $request->input('annee_id') : null;
        $batimentId = $request->filled('batiment_id') ? (int) $request->input('batiment_id') : null;
        $recherche = $request->input('recherche');

        $auditSalles = $this->planificationSallesOrchestrator->auditerSallesEtCapacites($faculteId, $batimentId);

        $salles = Room::query()
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->when($batimentId !== null, fn ($q) => $q->where('batiment_id', $batimentId))
            ->when($recherche !== null && $recherche !== '', function ($q) use ($recherche) {
                $q->where(fn ($r) => $r->where('nom', 'like', "%{$recherche}%")->orWhere('code', 'like', "%{$recherche}%"));
            })
            ->with(['batimentRelation'])
            ->orderBy('code')
            ->paginate(20)
            ->withQueryString();

        $commun = $this->commonExamensAdvancedVars($anneeId, $faculteId);

        return view('modules.evaluation.examens-advanced.salles', [
            'salles' => $salles,
            'recherche' => $recherche,
            'batimentId' => $batimentId,
            'batiments' => $auditSalles['batiments'],
            'kpisSalles' => $auditSalles['kpis'],
            'sallesBloquantes' => $auditSalles['salles_bloquantes_liste'],
            'ratioDefaut' => ExamScheduleEngineService::RATIO_CAPACITE_EXAMEN_DEFAUT,
        ] + $commun);
    }

    /**
     * Menu 7 — Planification automatique
     * Route : /admin/evaluation/examens/planification
     */
    public function planification(Request $request): View
    {
        Gate::authorize('epreuves.view');

        $faculteId = $request->user()->faculteRestreinteId();
        $anneeId = $request->filled('annee_id') ? (int) $request->input('annee_id') : null;
        $examenId = $request->filled('examen_id') ? (int) $request->input('examen_id') : null;

        $examen = null;
        $scenarios = [];
        $analyseScenarios = null;
        $recommandation = null;
        $resumeComparatif = [];
        $erreurPlanification = null;

        if ($examenId !== null) {
            $examen = Examen::query()
                ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
                ->with(['epreuves.matiere', 'epreuves.salle'])
                ->find($examenId);

            if ($examen !== null && $examen->epreuves->isNotEmpty()) {
                try {
                    $resultatMoteur = $this->scheduleEngine->genererScenariosPlanning($examen);
                    $analyseScenarios = $this->planificationSallesOrchestrator->analyserEtEnrichirScenarios($resultatMoteur, $examen);
                    $scenarios = $analyseScenarios['scenarios'];
                    $recommandation = $analyseScenarios['recommandation'];
                    $resumeComparatif = $analyseScenarios['resume_comparatif'];
                } catch (\DomainException $e) {
                    $erreurPlanification = $e->getMessage();
                }
            }
        }

        $examens = Examen::query()
            ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
            ->when($anneeId !== null, fn ($q) => $q->where(function ($sub) use ($anneeId, $examenId) {
                $sub->where('annee_universitaire_id', $anneeId);
                if ($examenId !== null) {
                    $sub->orWhere('id', $examenId);
                }
            }))
            ->whereIn('statut', ['planned', 'scheduled', 'draft'])
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        $commun = $this->commonExamensAdvancedVars($anneeId, $faculteId);

        return view('modules.evaluation.examens-advanced.planification', [
            'examenSelectionne' => $examen,
            'scenarios' => $scenarios,
            'analyseScenarios' => $analyseScenarios,
            'recommandation' => $recommandation,
            'resumeComparatif' => $resumeComparatif,
            'erreurPlanification' => $erreurPlanification,
            'examens' => $examens,
            'examenId' => $examenId,
        ] + $commun);
    }

    /**
     * Menu 8 — Surveillance
     * Route : /admin/evaluation/examens/surveillance
     */
    public function surveillance(Request $request): View
    {
        Gate::authorize('epreuves.view');

        $faculteId = $request->user()->faculteRestreinteId();
        $anneeId = $request->filled('annee_id') ? (int) $request->input('annee_id') : null;
        $sessionId = $request->filled('session_id') ? (int) $request->input('session_id') : null;
        $dateFiltre = $request->input('date');
        $statutFiltre = $request->input('statut');
        $roleFiltre = $request->input('role');
        $recherche = $request->input('recherche');

        $auditSurveillance = $this->surveillanceGroupesOrchestrator->auditerSurveillance($sessionId, $faculteId, $dateFiltre);

        $surveillances = Surveillance::query()
            ->when($faculteId !== null, fn ($q) => $q->whereHas('epreuve', fn ($sq) => $sq->pourFaculteId($faculteId)))
            ->when($sessionId !== null, fn ($q) => $q->whereHas('epreuve', fn ($sq) => $sq->where('session_id', $sessionId)))
            ->when($dateFiltre !== null && $dateFiltre !== '', fn ($q) => $q->whereHas('epreuve', fn ($sq) => $sq->whereDate('date', $dateFiltre)))
            ->when($statutFiltre !== null && $statutFiltre !== '', fn ($q) => $q->where('statut', $statutFiltre))
            ->when($roleFiltre !== null && $roleFiltre !== '', fn ($q) => $q->where('role', $roleFiltre))
            ->when($recherche !== null && $recherche !== '', function ($q) use ($recherche) {
                $q->where(function ($sq) use ($recherche) {
                    $sq->whereHas('enseignant', fn ($eq) => $eq->where('nom', 'like', "%{$recherche}%")->orWhere('prenom', 'like', "%{$recherche}%"))
                        ->orWhereHas('epreuve', fn ($eq) => $eq->where('code', 'like', "%{$recherche}%")
                            ->orWhereHas('matiere', fn ($mq) => $mq->where('nom', 'like', "%{$recherche}%")->orWhere('code', 'like', "%{$recherche}%"))
                        );
                });
            })
            ->with(['epreuve.matiere', 'enseignant', 'salle'])
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $sessionsDisponibles = SessionExamen::query()
            ->when($anneeId !== null, fn ($q) => $q->where('annee_universitaire_id', $anneeId))
            ->orderByDesc('date_debut')
            ->get(['id', 'nom', 'code', 'statut']);

        $totalSurveillants = Teacher::where('actif', true)->count();
        $commun = $this->commonExamensAdvancedVars($anneeId, $faculteId);

        return view('modules.evaluation.examens-advanced.surveillance', [
            'surveillances' => $surveillances,
            'totalSurveillants' => $totalSurveillants,
            'kpisSurveillance' => $auditSurveillance['kpis'],
            'epreuvesAuditees' => $auditSurveillance['epreuves_auditees'],
            'sessionsDisponibles' => $sessionsDisponibles,
            'sessionFiltre' => $sessionId,
            'dateFiltre' => $dateFiltre,
            'statutFiltre' => $statutFiltre,
            'roleFiltre' => $roleFiltre,
            'recherche' => $recherche,
        ] + $commun);
    }

    /**
     * Menu 9 — Simulation et contrôle qualité
     * Route : /admin/evaluation/examens/simulation
     */
    public function simulation(Request $request): View
    {
        Gate::authorize('evaluations.view');

        $faculteId = $request->user()->faculteRestreinteId();
        $anneeId = $request->filled('annee_id') ? (int) $request->input('annee_id') : null;
        $examenId = $request->filled('examen_id') ? (int) $request->input('examen_id') : null;

        $examen = null;
        $audit = null;

        if ($examenId !== null) {
            $examen = Examen::query()
                ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
                ->find($examenId);

            if ($examen !== null) {
                $audit = $this->scheduleEngine->auditerSessionExamens($examen);
            }
        }

        $examens = Examen::query()
            ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        $commun = $this->commonExamensAdvancedVars($anneeId, $faculteId);

        return view('modules.evaluation.examens-advanced.simulation', [
            'examenSelectionne' => $examen,
            'audit' => $audit,
            'examens' => $examens,
            'examenId' => $examenId,
        ] + $commun);
    }

    /**
     * Menu 10 — Validation et publication
     * Route : /admin/evaluation/examens/publication
     */
    public function publication(Request $request): View
    {
        Gate::authorize('evaluations.view');

        $faculteId = $request->user()->faculteRestreinteId();
        $anneeId = $request->filled('annee_id') ? (int) $request->input('annee_id') : null;
        $statutFiltre = $request->input('statut');
        $recherche = $request->input('recherche');

        $auditPublication = $this->publicationOrchestrator->auditerPublication(
            $anneeId,
            $faculteId,
            $statutFiltre,
            $recherche,
            15
        );

        $examensPrets = $auditPublication['examensPrets'];
        $statsPublication = $auditPublication['statsPublication'];

        $annees = AnneeUniversitaire::orderByDesc('date_debut')->get();
        $commun = $this->commonExamensAdvancedVars($anneeId, $faculteId);

        return view('modules.evaluation.examens-advanced.publication', [
            'examensPrets' => $examensPrets,
            'statsPublication' => $statsPublication,
            'annees' => $annees,
            'anneeId' => $anneeId,
            'statutFiltre' => $statutFiltre,
            'recherche' => $recherche,
        ] + $commun);
    }

    /**
     * Menu 11 — Convocations
     * Route : /admin/evaluation/examens/convocations
     */
    public function convocations(Request $request): View
    {
        Gate::authorize('evaluations.view');

        $faculteId = $request->user()->faculteRestreinteId();
        $anneeId = $request->filled('annee_id') ? (int) $request->input('annee_id') : null;
        $sessionId = $request->filled('session_id') ? (int) $request->input('session_id') : null;
        $recherche = $request->input('recherche');
        $dateFiltre = $request->input('date');

        $epreuvesPubliees = Epreuve::query()
            ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
            ->whereIn('statut', ['published', 'ongoing', 'completed'])
            ->when($sessionId !== null, fn ($q) => $q->where('session_id', $sessionId))
            ->when($dateFiltre !== null && $dateFiltre !== '', fn ($q) => $q->whereDate('date', $dateFiltre))
            ->when($recherche !== null && $recherche !== '', fn ($q) => $q->where(fn ($sq) => $sq->where('code', 'like', "%{$recherche}%")
                ->orWhereHas('matiere', fn ($mq) => $mq->where('nom', 'like', "%{$recherche}%")->orWhere('code', 'like', "%{$recherche}%"))
                ->orWhereHas('salle', fn ($rq) => $rq->where('code', 'like', "%{$recherche}%")->orWhere('nom', 'like', "%{$recherche}%"))
            ))
            ->with(['matiere', 'salle', 'session'])
            ->withCount('candidats')
            ->orderByDesc('date')
            ->paginate(15)
            ->withQueryString();

        $documentsGeneres = DocumentExamenGenere::query()
            ->latest('id')
            ->limit(10)
            ->get();

        $statsConvocations = $this->candidatsConvocationsOrchestrator->auditerConvocations($sessionId, $faculteId);

        $sessionsDisponibles = SessionExamen::query()
            ->when($anneeId !== null, fn ($q) => $q->where('annee_universitaire_id', $anneeId))
            ->orderByDesc('date_debut')
            ->get(['id', 'nom', 'code', 'statut']);

        $annees = AnneeUniversitaire::orderByDesc('date_debut')->get();
        $commun = $this->commonExamensAdvancedVars($anneeId, $faculteId);

        return view('modules.evaluation.examens-advanced.convocations', [
            'epreuvesPubliees' => $epreuvesPubliees,
            'documentsGeneres' => $documentsGeneres,
            'statsConvocations' => $statsConvocations,
            'sessionsDisponibles' => $sessionsDisponibles,
            'annees' => $annees,
            'anneeId' => $anneeId,
            'sessionFiltre' => $sessionId,
            'recherche' => $recherche,
            'dateFiltre' => $dateFiltre,
        ] + $commun);
    }

    /**
     * Menu 12 — Historique et audit
     * Route : /admin/evaluation/examens/historique
     */
    public function historique(Request $request): View
    {
        Gate::authorize('evaluations.view');

        $faculteId = $request->user()->faculteRestreinteId();
        $anneeId = $request->filled('annee_id') ? (int) $request->input('annee_id') : null;
        $statutFiltre = $request->input('statut');
        $directionFiltre = $request->input('direction');
        $recherche = $request->input('recherche');
        $dateDebut = $request->input('date_debut');
        $dateFin = $request->input('date_fin');

        $auditHistorique = $this->historiqueAuditOrchestrator->auditerHistorique(
            $anneeId,
            $faculteId,
            $statutFiltre,
            $directionFiltre,
            $recherche,
            $dateDebut,
            $dateFin,
            25
        );

        $commun = $this->commonExamensAdvancedVars($anneeId, $faculteId);

        return view('modules.evaluation.examens-advanced.historique', [
            'traces' => $auditHistorique['traces'],
            'statsHistorique' => $auditHistorique['statsHistorique'],
            'derniersDocuments' => $auditHistorique['derniersDocuments'],
            'statutFiltre' => $statutFiltre,
            'directionFiltre' => $directionFiltre,
            'recherche' => $recherche,
            'dateDebut' => $dateDebut,
            'dateFin' => $dateFin,
        ] + $commun);
    }
}
