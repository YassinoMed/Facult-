<?php

namespace App\Modules\Evaluation\Controllers;

use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Requests\StoreExamenRequest;
use App\Modules\Evaluation\Services\ExamenService;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ExamenController
{
    public function index(): View
    {
        Gate::authorize('evaluations.view');

        // Isolation faculté (audit sécurité 04/10/2026) : un compte
        // restreint ne liste que son périmètre (transversal inclus).
        $examens = Examen::with(['session', 'programme', 'groupe'])
            ->when($faculteId = request()->user()->faculteRestreinteId(), fn ($q) => $q->pourFaculteId($faculteId))
            ->orderByDesc('id')
            ->paginate(20);

        return view('modules.evaluation.examens-index', ['examens' => $examens]);
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

        try {
            app(ExamenService::class)->transiter($examen, $cible);
        } catch (DomainException $e) {
            return back()->withErrors(['examen' => $e->getMessage()]);
        }

        return back()->with('succes', "Examen passé à « {$cible} ».");
    }
}
