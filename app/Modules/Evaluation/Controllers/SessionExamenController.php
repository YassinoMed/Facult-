<?php

namespace App\Modules\Evaluation\Controllers;

use App\Models\AnneeUniversitaire;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Requests\StoreSessionExamenRequest;
use App\Modules\Evaluation\Services\SessionExamenService;
use App\Modules\Evaluation\Services\SessionPreparationService;
use App\Modules\Evaluation\Services\SurveillanceEquilibrageService;
use App\Modules\Referentiel\Models\Programme;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SessionExamenController
{
    public function index(): View
    {
        Gate::authorize('sessions_examen.view');

        $sessions = SessionExamen::with('anneeUniversitaire')
            ->orderByDesc('date_debut')
            ->paginate(20);

        return view('modules.evaluation.sessions-index', ['sessions' => $sessions]);
    }

    public function create(): View
    {
        Gate::authorize('sessions_examen.create');

        return view('modules.evaluation.sessions-form', [
            'annees' => AnneeUniversitaire::orderByDesc('libelle')->get(),
            'principales' => SessionExamen::where('type', 'principale')
                ->where('statut', '!=', 'archived')
                ->with('anneeUniversitaire')->orderByDesc('date_debut')->get(),
        ]);
    }

    public function store(StoreSessionExamenRequest $request): RedirectResponse
    {
        $donnees = $request->validated();

        if (SessionExamen::where('annee_universitaire_id', $donnees['annee_universitaire_id'])
            ->where('date_debut', '<=', $donnees['date_fin'])
            ->where('date_fin', '>=', $donnees['date_debut'])
            ->where('statut', '!=', 'archived')
            ->exists()
        ) {
            return back()->withErrors(['date_debut' => 'Une session incompatible couvre déjà cette période.'])->withInput();
        }

        $session = SessionExamen::create([
            ...$donnees,
            'statut' => 'draft',
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('admin.evaluations.sessions.index')
            ->with('succes', "Session « {$session->nom} » créée.");
    }

    public function verrouiller(Request $request, SessionExamen $session): RedirectResponse
    {
        Gate::authorize('sessions_examen.lock', $session);

        if ($session->estVerrouillee()) {
            return back()->withErrors(['session' => 'Session déjà verrouillée.']);
        }

        if (! $session->peutTransiterVers('locked')) {
            return back()->withErrors(['session' => "Verrouillage interdit depuis « {$session->statut} » (clôturez d'abord la session)."]);
        }

        $session->update(['statut' => 'locked', 'verrouillee_at' => now()]);

        return back()->with('succes', 'Session verrouillée.');
    }

    /**
     * Assistant de préparation (§13.3) : périmètre, épreuves générées,
     * readiness, publication — progression en 5 étapes.
     */
    public function show(Request $request, SessionExamen $session, SessionExamenService $service): View
    {
        Gate::authorize('view', $session);

        $session->load(['epreuves.matiere', 'epreuves.salle', 'epreuves.groupe']);

        return view('modules.evaluation.sessions-show', [
            'session' => $session,
            'readiness' => $service->readiness($session),
            'programmes' => Programme::orderBy('code')->get(),
        ]);
    }

    public function transiter(Request $request, SessionExamen $session, string $cible, SessionExamenService $service): RedirectResponse
    {
        Gate::authorize('update', $session);

        try {
            $service->transiter($session, $cible);
        } catch (DomainException $e) {
            return back()->withErrors(['session' => $e->getMessage()]);
        }

        return back()->with('succes', "Session passée à « {$cible} ».");
    }

    public function generer(Request $request, SessionExamen $session, SessionPreparationService $preparation): RedirectResponse
    {
        Gate::authorize('update', $session);

        $donnees = $request->validate([
            'programme_id' => ['required', 'exists:programmes,id'],
            'niveau' => ['nullable', 'in:L1,L2,L3,M1,M2,D1,D2,D3'],
            'date_debut' => ['required', 'date'],
            'heure_debut' => ['nullable', 'date_format:H:i'],
            'heure_fin' => ['nullable', 'date_format:H:i', 'after:heure_debut'],
        ]);

        // Isolation faculté (audit sécurité 04/10/2026) : la session est
        // transversale, mais la CIBLE de génération (programme) ne l'est
        // pas — scope → 404 avant toute écriture.
        $programme = Programme::findOrFail($donnees['programme_id']);
        abort_unless($programme->faculte_id === null
            || $request->user()->peutAccederFaculte((int) $programme->faculte_id), 404);

        try {
            $resultat = $preparation->genererEpreuves(
                $session,
                (int) $donnees['programme_id'],
                $donnees['niveau'] ?? null,
                $donnees['date_debut'],
                $donnees['heure_debut'] ?? '09:00',
                $donnees['heure_fin'] ?? '11:00',
            );
        } catch (DomainException $e) {
            return back()->withErrors(['generation' => $e->getMessage()])->withInput();
        }

        return back()->with('succes', "{$resultat['creees']} épreuve(s) générée(s), ".count($resultat['ignorees']).' ignorée(s).');
    }

    public function autoSurveillants(Request $request, SessionExamen $session, SessionPreparationService $preparation): RedirectResponse
    {
        Gate::authorize('update', $session);

        $nombre = $request->validate(['nombre' => ['required', 'integer', 'min:1', 'max:20']])['nombre'];
        $assignes = 0;

        foreach ($session->epreuves as $epreuve) {
            $resultat = $preparation->affecterSurveillantsAuto($epreuve, $nombre);
            $assignes += $resultat['assignes'];
        }

        return back()->with('succes', "{$assignes} surveillance(s) affectée(s).");
    }

    public function simulerEquilibrage(Request $request, SessionExamen $session, SurveillanceEquilibrageService $equilibrage): JsonResponse|RedirectResponse
    {
        Gate::authorize('update', $session);

        $resultat = $equilibrage->simulerSession($session);

        if ($request->wantsJson()) {
            return response()->json($resultat);
        }

        return back()->with('simulation_equilibrage', $resultat);
    }

    public function appliquerEquilibrage(Request $request, SessionExamen $session, SurveillanceEquilibrageService $equilibrage): RedirectResponse
    {
        Gate::authorize('update', $session);

        $simulation = $equilibrage->simulerSession($session);

        if ($simulation['statut'] === 'IMPOSSIBLE') {
            return back()->withErrors(['equilibrage' => $simulation['message_statut']]);
        }

        try {
            $bilan = $equilibrage->appliquerSession($session, $simulation, (int) $request->user()->id);
        } catch (DomainException $e) {
            return back()->withErrors(['equilibrage' => $e->getMessage()]);
        }

        return back()->with('succes', "Équilibrage appliqué : {$bilan['assignes']} surveillance(s) et garde(s) affectée(s) sur {$bilan['epreuves_mises_a_jour']} épreuve(s).");
    }
}
