<?php

namespace App\Modules\SimulationTemporelle\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Schedule\Models\Group;
use App\Modules\SimulateurScenarios\Support\FacultePerimetre;
use App\Modules\SimulationTemporelle\Models\ExecutionTemporelle;
use App\Modules\SimulationTemporelle\Models\ScenarioTemporel;
use App\Modules\SimulationTemporelle\Services\ServiceSimulationTemporelle;
use App\Modules\SimulationTemporelle\Support\AuditeurQualiteDonnees;
use App\Modules\SimulationTemporelle\Support\ExplicateurIaSimulation;
use App\Modules\SimulationTemporelle\Support\ExporteurSimulation;
use App\Modules\SimulationTemporelle\Support\GouvernancePlanificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Simulateur temporel — contrôleur JSON (UI différée, pattern série).
 * Permissions RÉUTILISÉES : `statistiques.view` ; périmètre serveur via
 * FacultePerimetre (Lot 14). Aucune écriture métier : uniquement les 3
 * tables du module.
 */
final class SimulationTemporelleController extends Controller
{
    public function __construct(
        private readonly ServiceSimulationTemporelle $service,
        private readonly ExporteurSimulation $exporteur = new ExporteurSimulation,
        private readonly ExplicateurIaSimulation $explicateurIa = new ExplicateurIaSimulation,
        private readonly GouvernancePlanificationService $gouvernance = new GouvernancePlanificationService,
        private readonly AuditeurQualiteDonnees $auditeurQualite = new AuditeurQualiteDonnees,
    ) {}

    /**
     * Lot B : écran du simulateur (Blade + Alpine, conventions du dépôt).
     * Les données viennent EXCLUSIVEMENT des endpoints d'options (base de
     * données via relations existantes) — aucune liste codée en dur.
     */
    public function index(Request $requete)
    {
        Gate::authorize('statistiques.view');

        $user = $requete->user();
        $restreinte = FacultePerimetre::resoudre($user, null);

        $facultes = Faculte::query()
            ->where('active', true)
            ->when($restreinte !== null, fn ($q) => $q->where('id', $restreinte))
            ->orderBy('nom')
            ->get(['id', 'nom', 'code']);

        $annee = AnneeUniversitaire::query()->where('active', true)->orderByDesc('id')->first();

        return view('modules.simulation-temporel.index', [
            'urls' => [
                'niveaux' => route('admin.simulation-temporelle.options.niveaux'),
                'groupes' => route('admin.simulation-temporelle.options.groupes', ['niveauId' => '__NIVEAU__']),
                'creer' => route('admin.simulation-temporelle.scenarios.creer'),
                'executer' => route('admin.simulation-temporelle.executer', ['id' => '__ID__']),
                'auditQualite' => route('admin.simulation-temporelle.audit-qualite'),
                'exportCsv' => route('admin.simulation-temporelle.executions.export.csv', ['id' => '__ID__']),
                'exportPdf' => route('admin.simulation-temporelle.executions.export.pdf', ['id' => '__ID__']),
                'expliquerIa' => route('admin.simulation-temporelle.executions.expliquer-ia', ['id' => '__ID__']),
                'proposerGov' => route('admin.simulation-temporelle.executions.gouvernance.proposer', ['id' => '__ID__']),
                'deciderGov' => route('admin.simulation-temporelle.executions.gouvernance.decider', ['id' => '__ID__']),
                'dupliquer' => route('admin.simulation-temporelle.scenarios.dupliquer', ['id' => '__ID__']),
                'nouvelleVersion' => route('admin.simulation-temporelle.scenarios.nouvelle-version', ['id' => '__ID__']),
            ],
            'anneeId' => $annee?->id,
            'anneeLibelle' => $annee?->libelle ?? '2026-2027',
            'facultes' => $facultes,
            'faculteIdParDefaut' => $restreinte ?? $facultes->first()?->id,
            'limites' => [
                'horizonMax' => ServiceSimulationTemporelle::PLAFOND_HORIZON,
                'variantesMax' => ServiceSimulationTemporelle::PLAFOND_VARIANTES,
            ],
        ]);
    }

    public function scenariosIndex(Request $requete): JsonResponse
    {
        Gate::authorize('statistiques.view');
        $restreinte = FacultePerimetre::resoudre($requete->user(), null);

        return response()->json([
            'scenarios' => ScenarioTemporel::query()
                ->when($restreinte !== null, fn ($q) => $q->where('faculte_id', $restreinte))
                ->orderBy('code')->limit(200)
                ->get(['id', 'code', 'libelle', 'domaine', 'faculte_id', 'version']),
        ]);
    }

    public function scenarioCreer(Request $requete): JsonResponse
    {
        Gate::authorize('statistiques.view');

        $donnees = $requete->validate([
            'code' => ['required', 'string', 'max:40', 'unique:scenarios_temporels,code'],
            'libelle' => ['required', 'string', 'max:200'],
            'faculte_id' => ['nullable', 'integer', 'exists:facultes,id'],
            'criteres' => ['required', 'array'],
            'contraintes' => ['present', 'array'],
            'objectifs' => ['required', 'array'],
        ]);

        if (($donnees['faculte_id'] ?? null) !== null && ! $requete->user()->peutAccederFaculte((int) $donnees['faculte_id'])) {
            abort(403, 'Simulation : faculté hors périmètre.');
        }

        try {
            $scenario = $this->service->definir($donnees, (int) $requete->user()->id);
        } catch (\DomainException $e) {
            return response()->json(['erreur' => $e->getMessage()], 422);
        }

        return response()->json(['scenario' => $scenario], 201);
    }

    public function executer(Request $requete, int $id): JsonResponse
    {
        Gate::authorize('statistiques.view');

        $scenario = ScenarioTemporel::query()->findOrFail($id);

        if ($scenario->faculte_id !== null && ! $requete->user()->peutAccederFaculte((int) $scenario->faculte_id)) {
            abort(403, 'Simulation : faculté hors périmètre.');
        }

        $donnees = $requete->validate([
            'annee_universitaire_id' => ['required', 'integer', 'exists:annees_universitaires,id'],
            'graine' => ['required', 'string', 'max:64'],
            'nb_variantes' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        try {
            $execution = $this->service->executer(
                $scenario,
                (int) $donnees['annee_universitaire_id'],
                $donnees['graine'],
                (int) $donnees['nb_variantes'],
                (int) $requete->user()->id,
            );
            // CORR-1 (ST-07) : l'auteur n'est tracé qu'à la CRÉATION —
            // la voie idempotente ne réécrit jamais l'exécution existante.
            if ($execution->wasRecentlyCreated && $execution->cree_par === null) {
                $execution->forceFill(['cree_par' => (int) $requete->user()->id])->save();
            }
        } catch (\DomainException $e) {
            return response()->json(['erreur' => $e->getMessage()], 422);
        }

        return response()->json([
            'execution' => $execution,
            'idempotente' => $execution->wasRecentlyCreated ? 'nouvelle' : 'existante (mêmes entrées — résultat figé)',
        ]);
    }

    /**
     * HG-02 : options en cascade — niveaux du périmètre, puis groupes
     * ACTIFS rattachés au niveau choisi (relation EXISTANTE groups.niveau_id).
     * Les groupes sans rattachement LMD sont exclus et signalés, jamais
     * rattachés implicitement.
     */
    public function optionsNiveaux(Request $requete): JsonResponse
    {
        Gate::authorize('statistiques.view');
        $demandee = $requete->has('faculte_id') && $requete->query('faculte_id') !== '' ? (int) $requete->query('faculte_id') : null;
        $restreinte = FacultePerimetre::resoudre($requete->user(), $demandee);

        $niveaux = Niveau::query()
            ->with('programme:id,code,nom,faculte_id')
            ->whereHas('programme', fn ($p) => $p
                ->when($restreinte !== null, fn ($q) => $q->where('faculte_id', $restreinte)))
            ->orderBy('ordre')->limit(300)
            ->get(['id', 'code', 'nom', 'programme_id']);

        return response()->json(['niveaux' => $niveaux]);
    }

    public function optionsGroupes(Request $requete, int $niveauId): JsonResponse
    {
        Gate::authorize('statistiques.view');
        $demandee = $requete->has('faculte_id') && $requete->query('faculte_id') !== '' ? (int) $requete->query('faculte_id') : null;
        $restreinte = FacultePerimetre::resoudre($requete->user(), $demandee);

        $niveau = Niveau::query()->with('programme:id,faculte_id')->find($niveauId)
            ?? abort(404, 'Niveau introuvable.');

        if ($niveau->programme?->faculte_id !== null
            && ! $requete->user()->peutAccederFaculte((int) $niveau->programme->faculte_id)) {
            abort(403, 'Simulation : niveau hors périmètre.');
        }

        $groupes = Group::query()
            ->where('niveau_id', $niveauId)
            ->where('actif', true)
            ->when($restreinte !== null, fn ($q) => $q->where('faculte_id', $restreinte))
            ->orderBy('code')->limit(300)
            ->get(['id', 'code', 'nom', 'capacite']);

        return response()->json([
            'niveau' => ['id' => $niveau->id, 'code' => $niveau->code],
            'groupes' => $groupes,
            'note' => 'Un groupe sans rattachement LMD (niveau_id NULL) n\'apparaît pas ici : il est incompatible avec le filtre par niveau.',
        ]);
    }

    public function executionsIndex(Request $requete): JsonResponse
    {
        Gate::authorize('statistiques.view');
        $restreinte = FacultePerimetre::resoudre($requete->user(), null);

        return response()->json([
            'executions' => ExecutionTemporelle::query()
                ->with('scenario:id,code,libelle,faculte_id')
                ->when($restreinte !== null, fn ($q) => $q->whereHas(
                    'scenario',
                    fn ($s) => $s->where('faculte_id', $restreinte)
                ))
                ->orderByDesc('id')->limit(100)
                ->get(['id', 'scenario_temporel_id', 'statut', 'hash_resultat',
                    'nb_variantes', 'horizon_annees', 'duree_ms', 'created_at']),
        ]);
    }

    public function executionDetail(Request $requete, int $id): JsonResponse
    {
        Gate::authorize('statistiques.view');

        $execution = ExecutionTemporelle::query()->with(['scenario:id,code,libelle,faculte_id', 'snapshots'])->findOrFail($id);
        $faculte = $execution->scenario?->faculte_id;

        if ($faculte !== null && ! $requete->user()->peutAccederFaculte((int) $faculte)) {
            abort(403, 'Simulation : faculté hors périmètre.');
        }

        return response()->json(['execution' => $execution]);
    }

    /**
     * Audit préventif de qualité des données (Lot 1).
     */
    public function auditQualite(Request $requete): JsonResponse
    {
        Gate::authorize('statistiques.view');

        $faculteId = $requete->has('faculte_id') && $requete->query('faculte_id') !== '' ? (int) $requete->query('faculte_id') : null;
        $restreinte = FacultePerimetre::resoudre($requete->user(), $faculteId);
        $anneeId = (int) ($requete->query('annee_id') ?: AnneeUniversitaire::where('active', true)->value('id'));
        $niveauId = $requete->has('niveau_id') && $requete->query('niveau_id') !== '' ? (int) $requete->query('niveau_id') : null;

        $audit = $this->auditeurQualite->auditer($restreinte, $anneeId, $niveauId);

        return response()->json(['audit' => $audit]);
    }

    /**
     * Dupliquer un scénario (Lot 4).
     */
    public function scenarioDupliquer(Request $requete, int $id): JsonResponse
    {
        Gate::authorize('statistiques.view');
        $source = ScenarioTemporel::findOrFail($id);

        if ($source->faculte_id !== null && ! $requete->user()->peutAccederFaculte((int) $source->faculte_id)) {
            abort(403, 'Simulation : faculté hors périmètre.');
        }

        $donnees = $requete->validate([
            'nouveau_code' => ['required', 'string', 'max:40', 'unique:scenarios_temporels,code'],
        ]);

        $cloned = $this->service->dupliquer($source, $donnees['nouveau_code'], (int) $requete->user()->id);

        return response()->json(['scenario' => $cloned], 201);
    }

    /**
     * Créer une nouvelle version d'un scénario (Lot 4).
     */
    public function scenarioNouvelleVersion(Request $requete, int $id): JsonResponse
    {
        Gate::authorize('statistiques.view');
        $source = ScenarioTemporel::findOrFail($id);

        if ($source->faculte_id !== null && ! $requete->user()->peutAccederFaculte((int) $source->faculte_id)) {
            abort(403, 'Simulation : faculté hors périmètre.');
        }

        $donnees = $requete->validate([
            'code' => ['nullable', 'string', 'max:40', 'unique:scenarios_temporels,code'],
            'libelle' => ['nullable', 'string', 'max:200'],
            'criteres' => ['required', 'array'],
            'contraintes' => ['present', 'array'],
            'objectifs' => ['required', 'array'],
        ]);

        $nouvelle = $this->service->nouvelleVersion($source, $donnees, (int) $requete->user()->id);

        return response()->json(['scenario' => $nouvelle], 201);
    }

    /**
     * Export CSV protégé anti-formules (Lot 8).
     */
    public function exportCsv(Request $requete, int $id)
    {
        Gate::authorize('statistiques.view');
        $execution = ExecutionTemporelle::with('scenario')->findOrFail($id);

        if ($execution->scenario?->faculte_id !== null && ! $requete->user()->peutAccederFaculte((int) $execution->scenario->faculte_id)) {
            abort(403, 'Simulation : faculté hors périmètre.');
        }

        return $this->exporteur->exporterCsv($execution);
    }

    /**
     * Export PDF officiel certifié (Lot 8).
     */
    public function exportPdf(Request $requete, int $id): Response
    {
        Gate::authorize('statistiques.view');
        $execution = ExecutionTemporelle::with('scenario')->findOrFail($id);

        if ($execution->scenario?->faculte_id !== null && ! $requete->user()->peutAccederFaculte((int) $execution->scenario->faculte_id)) {
            abort(403, 'Simulation : faculté hors périmètre.');
        }

        $pdfFlux = $this->exporteur->genererPdf($execution);
        $nomFichier = 'simulation_'.preg_replace('/[^a-zA-Z0-9_\-]/', '_', $execution->scenario->code)."_exec_{$execution->id}.pdf";

        return response($pdfFlux, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$nomFichier}\"",
        ]);
    }

    /**
     * Assistant IA explicatif avec preuves mathématiques (Lot 12).
     */
    public function expliquerIa(Request $requete, int $id): JsonResponse
    {
        Gate::authorize('statistiques.view');
        $execution = ExecutionTemporelle::with('scenario')->findOrFail($id);

        $donnees = $requete->validate([
            'question' => ['required', 'string', 'max:500'],
        ]);

        try {
            $explication = $this->explicateurIa->expliquer($execution, $donnees['question'], $requete->user());
        } catch (\DomainException $e) {
            return response()->json(['erreur' => $e->getMessage()], 403);
        }

        return response()->json($explication);
    }

    /**
     * Soumission d'une proposition pour revue à 4 yeux (Lot 14).
     */
    public function gouvernanceProposer(Request $requete, int $id): JsonResponse
    {
        Gate::authorize('statistiques.view');
        $execution = ExecutionTemporelle::with('scenario')->findOrFail($id);

        $donnees = $requete->validate([
            'titre' => ['required', 'string', 'max:150'],
            'justification' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $prop = $this->gouvernance->soumettreProposition($execution, $requete->user(), $donnees['titre'], $donnees['justification']);
        } catch (\DomainException $e) {
            return response()->json(['erreur' => $e->getMessage()], 422);
        }

        return response()->json(['proposition' => $prop], 201);
    }

    /**
     * Décision (approbation ou rejet) par un validateur tiers (Lot 14).
     */
    public function gouvernanceDecider(Request $requete, int $id): JsonResponse
    {
        Gate::authorize('statistiques.view');
        $execution = ExecutionTemporelle::with('scenario')->findOrFail($id);

        $donnees = $requete->validate([
            'decision' => ['required', 'in:approuvee,rejetee'],
            'motif' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $prop = $this->gouvernance->decider($execution, $requete->user(), $donnees['decision'], $donnees['motif']);
        } catch (\DomainException $e) {
            return response()->json(['erreur' => $e->getMessage()], 422);
        }

        return response()->json(['proposition' => $prop]);
    }
}
