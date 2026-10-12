<?php

namespace App\Modules\Evaluation\Controllers;

use App\Models\Etudiant;
use App\Models\ImportReport;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Evaluation\Models\JuryMembre;
use App\Modules\Evaluation\Requests\StoreDeliberationRequest;
use App\Modules\Evaluation\Services\DeliberationService;
use App\Modules\Evaluation\Services\PvDeliberationService;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Modules\StudentDossiers\Jobs\GenerateOfficialDocumentsJob;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class DeliberationController
{
    public function index(): View
    {
        Gate::authorize('deliberations.view');

        // Isolation faculté (audit sécurité 04/10/2026) : un compte
        // restreint ne voit que son périmètre (transversal inclus).
        $deliberations = Deliberation::with(['anneeUniversitaire', 'session', 'programme'])
            ->when($faculteId = request()->user()->faculteRestreinteId(), fn ($q) => $q->pourFaculteId($faculteId))
            ->orderByDesc('id')
            ->paginate(20);

        return view('modules.evaluation.deliberations-index', ['deliberations' => $deliberations]);
    }

    public function show(Deliberation $deliberation): View
    {
        Gate::authorize('view', $deliberation);

        $deliberation->load(['decisions.etudiant', 'membres.utilisateur', 'membres.enseignant']);

        return view('modules.evaluation.deliberations-show', ['deliberation' => $deliberation]);
    }

    public function store(StoreDeliberationRequest $request): RedirectResponse
    {
        $donnees = $request->validated();

        // Isolation faculté (audit sécurité 04/10/2026) : la cible
        // (programme/groupe) doit relever du périmètre — scope → 404.
        $programme = isset($donnees['programme_id']) ? Programme::findOrFail($donnees['programme_id']) : null;
        $groupe = isset($donnees['groupe_id']) ? Group::findOrFail($donnees['groupe_id']) : null;
        abort_unless($programme?->faculte_id === null
            || $request->user()->peutAccederFaculte((int) $programme->faculte_id), 404);
        abort_unless($groupe === null
            || $request->user()->peutAccederFaculte((int) $groupe->faculte_id), 404);

        $deliberation = Deliberation::create([
            ...$donnees,
            'statut' => 'draft',
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('admin.evaluations.deliberations.show', $deliberation)
            ->with('succes', 'Délibération créée.');
    }

    public function preparer(Deliberation $deliberation, DeliberationService $service): RedirectResponse
    {
        Gate::authorize('deliberer', $deliberation);

        try {
            $service->preparer($deliberation);
        } catch (DomainException $e) {
            return back()->withErrors(['deliberation' => $e->getMessage()]);
        }

        return back()->with('succes', 'Tableau de délibération préparé.');
    }

    public function simuler(Deliberation $deliberation, DeliberationService $service): View
    {
        Gate::authorize('deliberer', $deliberation);

        $simulation = $service->simuler($deliberation);
        $noms = Etudiant::whereIn('id', array_column($simulation['decisions'], 'etudiant_id'))
            ->get()->mapWithKeys(fn ($e) => [$e->id => $e->nom_complet]);

        return view('modules.evaluation.deliberations-simulation', [
            'deliberation' => $deliberation,
            'simulation' => $simulation,
            'noms' => $noms,
        ]);
    }

    public function ajouterMembre(Request $request, Deliberation $deliberation): RedirectResponse
    {
        Gate::authorize('deliberer', $deliberation);

        $donnees = $request->validate([
            'user_id' => ['nullable', 'exists:users,id'],
            'teacher_id' => ['nullable', 'exists:teachers,id'],
            'fonction' => ['required', 'in:'.implode(',', JuryMembre::FONCTIONS)],
        ]);

        if (empty($donnees['user_id']) && empty($donnees['teacher_id'])) {
            return back()->withErrors(['membre' => 'Un utilisateur ou un enseignant est requis.']);
        }

        $deliberation->membres()->create($donnees);

        return back()->with('succes', 'Membre du jury ajouté.');
    }

    public function pointer(Request $request, JuryMembre $membre): RedirectResponse
    {
        Gate::authorize('deliberer', $membre->deliberation);

        $membre->update(['present' => ! $membre->present]);

        return back()->with('succes', 'Présence mise à jour.');
    }

    public function decider(Request $request, DecisionDeliberation $decision, DeliberationService $service): RedirectResponse
    {
        Gate::authorize('deliberer', $decision->deliberation);

        $request->validate([
            'decision' => ['required', 'in:'.implode(',', DecisionDeliberation::DECISIONS)],
            'commentaire' => ['nullable', 'string', 'max:1000'],
            'updated_at' => ['required', 'string'],
        ]);

        try {
            $service->decider(
                $decision,
                $request->string('decision')->toString(),
                $request->string('commentaire')->toString() ?: null,
                $request->string('updated_at')->toString()
            );
        } catch (DomainException $e) {
            return back()->withErrors(['decision' => $e->getMessage()]);
        }

        return back()->with('succes', 'Décision enregistrée.');
    }

    public function valider(Deliberation $deliberation, DeliberationService $service): RedirectResponse
    {
        Gate::authorize('deliberer', $deliberation);

        try {
            $service->valider($deliberation);
        } catch (DomainException $e) {
            return back()->withErrors(['deliberation' => $e->getMessage()]);
        }

        return back()->with('succes', 'Délibération validée et verrouillée.');
    }

    public function publier(Deliberation $deliberation, DeliberationService $service): RedirectResponse
    {
        Gate::authorize('deliberer', $deliberation);

        try {
            $service->publier($deliberation);
        } catch (DomainException $e) {
            return back()->withErrors(['deliberation' => $e->getMessage()]);
        }

        return back()->with('succes', 'Résultats publiés.');
    }

    /**
     * Génération massive des relevés (LOOP 4) : décisions publiées →
     * job en file + rapport suivi (permission publish, jamais en HTTP).
     */
    public function genererReleves(Deliberation $deliberation): RedirectResponse
    {
        Gate::authorize('deliberer', $deliberation);
        abort_unless($deliberation->statut === 'published', 422, 'Délibération non publiée.');

        $rapport = ImportReport::create([
            'user_id' => auth()->id(),
            'integration' => 'web',
            'direction' => 'import',
            'type' => 'data.releves',
            'format' => 'pdf',
            'filename' => "releves-deliberation-{$deliberation->id}",
            'status' => 'pending',
            'mode' => 'atomique',
        ]);

        GenerateOfficialDocumentsJob::dispatch($rapport->id, $deliberation->id, 'releve_notes', (int) auth()->id());

        return back()->with('succes', "Génération des relevés lancée (rapport #{$rapport->id}).");
    }

    /**
     * PV de délibération (Phase 14 §14.6) : périmètre via la policy,
     * émis uniquement sur délibération validée ou publiée.
     */
    public function pv(Deliberation $deliberation, PvDeliberationService $convocations): Response
    {
        Gate::authorize('view', $deliberation);

        try {
            return $convocations->generer($deliberation);
        } catch (DomainException $e) {
            abort(422, $e->getMessage());
        }
    }
}
