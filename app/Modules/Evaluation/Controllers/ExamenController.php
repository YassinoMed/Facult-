<?php

namespace App\Modules\Evaluation\Controllers;

use App\Models\AnneeUniversitaire;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Requests\StoreExamenRequest;
use App\Modules\Evaluation\Services\ExamenService;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Services\ExamScheduleEngineService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ExamenController
{
    public function index(): View
    {
        Gate::authorize('evaluations.view');

        $user = request()->user();
        $faculteId = $user?->faculteRestreinteId();

        // Isolation faculté (audit sécurité 04/10/2026) : un compte
        // restreint ne liste que son périmètre (transversal inclus).
        $examens = Examen::with(['session', 'programme', 'groupe'])
            ->when($faculteId, fn ($q) => $q->pourFaculteId($faculteId))
            ->orderByDesc('id')
            ->paginate(20);

        // Données nécessaires à l'interface de création manuelle d'un examen
        $sessions = SessionExamen::with('anneeUniversitaire')
            ->whereNull('verrouillee_at')
            ->whereNotIn('statut', ['locked', 'archived'])
            ->orderByDesc('date_debut')
            ->get();

        $programmes = Programme::query()
            ->when($faculteId, fn ($q) => $q->where('faculte_id', $faculteId))
            ->orderBy('nom')
            ->get();

        $groupes = Group::query()
            ->when($faculteId, fn ($q) => $q->where('faculte_id', $faculteId))
            ->orderBy('nom')
            ->get();

        $annees = AnneeUniversitaire::orderByDesc('date_debut')->get();

        return view('modules.evaluation.examens-index', [
            'examens' => $examens,
            'sessions' => $sessions,
            'programmes' => $programmes,
            'groupes' => $groupes,
            'annees' => $annees,
        ]);
    }

    public function store(StoreExamenRequest $request): RedirectResponse
    {
        $donnees = $request->validated();

        $session = SessionExamen::findOrFail($donnees['session_id']);
        Gate::authorize('create', $session);

        // Isolation faculté (audit sécurité 04/10/2026) : permission seule
        // insuffisante — la cible (programme/groupe) doit relever du
        // périmètre de l'utilisateur (scope → 404).
        $programme = isset($donnees['programme_id']) ? Programme::findOrFail($donnees['programme_id']) : null;
        $groupe = isset($donnees['groupe_id']) ? Group::findOrFail($donnees['groupe_id']) : null;
        abort_unless($programme?->faculte_id === null
            || $request->user()->peutAccederFaculte((int) $programme->faculte_id), 404);
        abort_unless($groupe === null
            || $request->user()->peutAccederFaculte((int) $groupe->faculte_id), 404);

        if ($session->estVerrouillee()) {
            throw new DomainException('Session verrouillée.');
        }

        $examen = Examen::create([
            ...$donnees,
            'statut' => 'draft',
            'responsable_id' => $request->user()->id,
        ]);

        return redirect()->route('admin.evaluations.examens.index')
            ->with('succes', "Examen « {$examen->libelle} » créé.");
    }

    public function transiter(Examen $examen, string $cible): RedirectResponse
    {
        Gate::authorize('update', $examen);

        // §2.7 / §2.8 — la publication officielle passe exclusivement par
        // le circuit de gouvernance (certification + double approbation).
        // Cette route de transition reste un outil de préparation : elle ne
        // doit jamais permettre une publication en un clic.
        if ($cible === 'published') {
            return back()->withErrors([
                'examen' => 'La publication officielle exige le circuit de gouvernance (certification + double approbation). Utilisez « Soumettre au circuit de validation ».',
            ]);
        }

        try {
            app(ExamenService::class)->transiter($examen, $cible);
        } catch (DomainException $e) {
            return back()->withErrors(['examen' => $e->getMessage()]);
        }

        return back()->with('succes', "Examen passé à « {$cible} ».");
    }

    public function genererScenarios(Request $request, Examen $examen, ExamScheduleEngineService $engine): JsonResponse|RedirectResponse
    {
        Gate::authorize('update', $examen);

        $faculteId = $examen->faculteId();
        abort_unless($faculteId === null || $request->user()->peutAccederFaculte($faculteId), 404);

        $options = $request->validate([
            'date_debut' => ['nullable', 'date'],
            'date_fin' => ['nullable', 'date', 'after_or_equal:date_debut'],
            'ratio_espacement' => ['nullable', 'numeric', 'min:0.1', 'max:1.0'],
            'autoriser_ecrasement' => ['nullable', 'boolean'],
        ]);

        try {
            $scenarios = $engine->genererScenariosPlanning($examen, $options);
        } catch (DomainException $e) {
            if ($request->wantsJson()) {
                return response()->json(['erreur' => $e->getMessage()], 422);
            }

            return back()->withErrors(['planification' => $e->getMessage()]);
        }

        if ($request->wantsJson()) {
            return response()->json($scenarios);
        }

        return back()->with('scenarios_planning', $scenarios);
    }

    public function appliquerScenario(Request $request, Examen $examen, ExamScheduleEngineService $engine): JsonResponse|RedirectResponse
    {
        Gate::authorize('update', $examen);

        $faculteId = $examen->faculteId();
        abort_unless($faculteId === null || $request->user()->peutAccederFaculte($faculteId), 404);

        $donnees = $request->validate([
            'scenario_id' => ['required', 'string'],
            'scenario_data' => ['required', 'array'],
            'forcer' => ['nullable', 'boolean'],
        ]);

        try {
            $bilan = $engine->appliquerScenario(
                $examen,
                $donnees['scenario_id'],
                $donnees['scenario_data'],
                $request->user()->id,
                $donnees['forcer'] ?? false
            );
        } catch (DomainException $e) {
            if ($request->wantsJson()) {
                return response()->json(['erreur' => $e->getMessage()], 422);
            }

            return back()->withErrors(['planification' => $e->getMessage()]);
        }

        if ($request->wantsJson()) {
            return response()->json($bilan);
        }

        return back()->with('succes', "Planning appliqué : {$bilan['epreuves_mises_a_jour']} épreuve(s) ordonnancée(s).");
    }

    public function auditConformite(Request $request, Examen $examen, ExamScheduleEngineService $engine): JsonResponse
    {
        Gate::authorize('view', $examen);

        $faculteId = $examen->faculteId();
        abort_unless($faculteId === null || $request->user()->peutAccederFaculte($faculteId), 404);

        $audit = $engine->auditerSessionExamens($examen);

        return response()->json($audit);
    }
}
