<?php

namespace App\Modules\Tasks\Controllers;

use App\Http\Controllers\Controller;
use App\Models\VueSauvegardee;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Services\TaskService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class TaskController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Task::class);

        $query = Task::with('assigne')
            ->when($request->input('statut'), fn ($q, $s) => $q->where('statut', $s))
            ->when($request->input('priorite'), fn ($q, $p) => $q->where('priorite', $p))
            ->when($request->boolean('miennes'), fn ($q) => $q->where('assigne_a', $request->user()->id))
            ->orderByRaw("CASE priorite WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
            ->orderBy('echeance_a')
            ->latest('id');

        if ($faculteId = $request->user()->faculteRestreinteId()) {
            $query->where(fn ($w) => $w->whereNull('faculte_id')->orWhere('faculte_id', $faculteId));
        }

        return view('modules.tasks.index', [
            'taches' => $query->paginate(15)->withQueryString(),
            'vues' => VueSauvegardee::where('user_id', $request->user()->id)
                ->where('tableau', 'taches')->orderBy('nom')->get(),
        ]);
    }

    public function show(Request $request, Task $tache): View
    {
        // Périmètre avant permission : hors faculté = 404 (anti-énumération).
        abort_unless($tache->faculte_id === null || $request->user()->peutAccederFaculte((int) $tache->faculte_id), 404);
        Gate::authorize('view', $tache);

        $tache->load(['evenements.auteur', 'assigne']);

        return view('modules.tasks.show', ['tache' => $tache]);
    }

    public function transiter(Request $request, Task $tache, string $cible, TaskService $service): RedirectResponse
    {
        abort_unless($tache->faculte_id === null || $request->user()->peutAccederFaculte((int) $tache->faculte_id), 404);
        Gate::authorize($cible === 'completed' ? 'complete' : 'update', $tache);

        $request->validate(['commentaire' => ['nullable', 'string', 'max:500']]);

        try {
            $service->transiter($tache, $cible, (int) $request->user()->id, $request->input('commentaire'));
        } catch (DomainException $e) {
            return back()->withErrors(['tache' => $e->getMessage()]);
        }

        return back()->with('success', 'Tâche mise à jour.');
    }

    public function assigner(Request $request, Task $tache, TaskService $service): RedirectResponse
    {
        abort_unless($tache->faculte_id === null || $request->user()->peutAccederFaculte((int) $tache->faculte_id), 404);
        Gate::authorize('assign', $tache);

        $request->validate(['assigne_a' => ['nullable', 'exists:users,id']]);

        $service->assigner($tache, $request->input('assigne_a') !== null ? (int) $request->input('assigne_a') : null, (int) $request->user()->id);

        return back()->with('success', 'Assignation enregistrée.');
    }

    public function actionGroupee(Request $request, TaskService $service): RedirectResponse
    {
        Gate::authorize('viewAny', Task::class);

        $request->validate([
            'ids' => ['required', 'array', 'max:100'],
            'ids.*' => ['integer', 'exists:tasks,id'],
            'action' => ['required', 'in:terminer,demarrer,assigner'],
            'assigne_a' => ['nullable', 'exists:users,id'],
        ]);

        // Périmètre : seules les tâches accessibles sont traitées.
        $ids = Task::whereIn('id', $request->input('ids'))
            ->when(
                $request->user()->faculteRestreinteId(),
                fn ($q, $f) => $q->where(fn ($w) => $w->whereNull('faculte_id')->orWhere('faculte_id', $f))
            )
            ->pluck('id')
            ->all();

        $resultat = $service->actionGroupee(
            $ids,
            $request->input('action'),
            (int) $request->user()->id,
            $request->input('assigne_a') !== null ? (int) $request->input('assigne_a') : null,
        );

        return back()->with('success', "{$resultat['traitees']} traitée(s), {$resultat['ignorees']} ignorée(s).");
    }
}
