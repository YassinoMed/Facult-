<?php

namespace App\Modules\Tasks\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
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
        Gate::authorize(match ($cible) {
            'completed' => 'complete',
            'cancelled' => 'cancel',
            default => 'update',
        }, $tache);

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

        $donnees = $request->validate(['assigne_a' => ['nullable', 'integer', 'exists:users,id']]);
        $assigneA = $donnees['assigne_a'] !== null ? (int) $donnees['assigne_a'] : null;

        if (($erreur = $this->erreurCibleAssignation($assigneA, [$tache])) !== null) {
            return back()->withErrors(['assigne_a' => $erreur]);
        }

        $service->assigner($tache, $assigneA, (int) $request->user()->id);

        return back()->with('success', 'Assignation enregistrée.');
    }

    /**
     * M-04 : la cible d'assignation doit être active, habilitée à voir les
     * tâches et, pour une tâche facultaire, appartenir au même périmètre.
     *
     * @param  iterable<int, Task>  $taches
     */
    private function erreurCibleAssignation(?int $assigneA, iterable $taches): ?string
    {
        if ($assigneA === null) {
            return null;
        }

        $cible = User::whereKey($assigneA)->where('actif', true)->first();

        if ($cible === null || ! $cible->hasPermission('tasks.view')) {
            return 'La cible doit être un compte actif disposant de la permission tasks.view.';
        }

        foreach ($taches as $tache) {
            if ($tache->faculte_id !== null && ! $cible->peutAccederFaculte((int) $tache->faculte_id)) {
                return 'La cible n’appartient pas au périmètre faculté de la tâche.';
            }
        }

        return null;
    }

    public function actionGroupee(Request $request, TaskService $service): RedirectResponse
    {
        Gate::authorize('viewAny', Task::class);

        $donnees = $request->validate([
            'ids' => ['required', 'array', 'max:100'],
            'ids.*' => ['integer', 'exists:tasks,id'],
            'action' => ['required', 'in:terminer,demarrer,assigner'],
            'assigne_a' => ['nullable', 'exists:users,id'],
        ]);

        $action = $donnees['action'];

        // La permission dépend de l'action : `tasks.update` ne doit jamais
        // suffire pour assigner ou clôturer (D-06). L'ability de policy
        // (assign/complete/update) est ensuite vérifiée tâche par tâche.
        [$permission, $ability] = match ($action) {
            'assigner' => ['tasks.assign', 'assign'],
            'terminer' => ['tasks.complete', 'complete'],
            'demarrer' => ['tasks.update', 'update'],
        };

        Gate::authorize($permission);

        // Périmètre : seules les tâches accessibles ET autorisées pour
        // cette action sont traitées. Aucun ID client n'est accepté tel quel.
        $taches = Task::whereIn('id', $donnees['ids'])
            ->when(
                $request->user()->faculteRestreinteId(),
                fn ($q, $f) => $q->where(fn ($w) => $w->whereNull('faculte_id')->orWhere('faculte_id', $f))
            )
            ->get()
            ->filter(fn (Task $tache) => Gate::allows($ability, $tache))
            ->values();

        if ($taches->isEmpty()) {
            return back()->withErrors([
                'ids' => 'Aucune tâche de votre périmètre ne peut être traitée avec cette action.',
            ]);
        }

        $assigneA = $donnees['assigne_a'] ?? null;

        // M-04 : cible active + tasks.view ; pour une tâche facultaire,
        // la cible doit aussi accéder à la faculté de la tâche.
        if ($action === 'assigner' && $assigneA !== null) {
            if (($erreur = $this->erreurCibleAssignation((int) $assigneA, $taches)) !== null) {
                return back()->withErrors(['assigne_a' => $erreur]);
            }
        }

        $resultat = $service->actionGroupee(
            $taches->pluck('id')->all(),
            $action,
            (int) $request->user()->id,
            $assigneA !== null ? (int) $assigneA : null,
        );

        return back()->with('success', "{$resultat['traitees']} traitée(s), {$resultat['ignorees']} ignorée(s).");
    }
}
