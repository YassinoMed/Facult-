<?php

namespace App\Modules\Schedule\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AnneeUniversitaire;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleAvailability;
use App\Modules\Schedule\Models\Teacher;
use App\Modules\Schedule\Requests\StoreScheduleAvailabilityRequest;
use App\Modules\Schedule\Requests\UpdateScheduleAvailabilityRequest;
use App\Modules\Schedule\Services\ScheduleAvailabilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * PHASE A (Smart Timetables) — gestion des disponibilités/indisponibilités
 * déclarées par ressource. Les plages `indisponible` sont des contraintes
 * dures (vérifiées par ScheduleConflictService) ; les plages `souhaitee`
 * sont des préférences non bloquantes.
 *
 * Un gestionnaire restreint à une faculté ne voit/agit que sur les plages
 * salle/groupe de son périmètre (plages enseignant transversales).
 */
class ScheduleAvailabilityController extends Controller
{
    public function __construct(
        private readonly ScheduleAvailabilityService $disponibilites,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Schedule::class);

        $query = ScheduleAvailability::with(['teacher', 'room', 'group.faculte', 'anneeUniversitaire']);

        // Scope faculté : enseignants transversaux, salles/groupes restreints.
        if ($faculteId = $request->user()->faculteRestreinteId()) {
            $query->where(function ($q) use ($faculteId) {
                $q->whereNotNull('teacher_id')
                    ->orWhereHas('room', fn ($r) => $r->where('faculte_id', $faculteId)->orWhereNull('faculte_id'))
                    ->orWhereHas('group', fn ($g) => $g->where('faculte_id', $faculteId));
            });
        }

        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }

        if ($jour = $request->input('jour')) {
            $query->where('day_of_week', $jour);
        }

        return view('modules.schedule.indisponibilites.index', [
            // Tri portable MySQL/SQLite : lundi → dimanche.
            'plages' => $query
                ->orderByRaw("CASE day_of_week WHEN 'monday' THEN 1 WHEN 'tuesday' THEN 2 WHEN 'wednesday' THEN 3 WHEN 'thursday' THEN 4 WHEN 'friday' THEN 5 WHEN 'saturday' THEN 6 ELSE 7 END")
                ->orderBy('start_time')
                ->paginate(20)
                ->withQueryString(),
            'jours' => ScheduleAvailability::JOURS,
            'types' => ScheduleAvailability::TYPES,
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('viewAny', Schedule::class);

        return view('modules.schedule.indisponibilites.form', $this->donneesFormulaire($request) + [
            'plage' => null,
        ]);
    }

    public function store(StoreScheduleAvailabilityRequest $request): RedirectResponse
    {
        $donnees = $request->validated();
        $this->autoriserRessource($request, $donnees);

        $resultat = $this->disponibilites->creer($donnees, $request->user()->id);

        if (isset($resultat['erreurs'])) {
            return redirect()->back()
                ->withErrors(['conflit' => implode(' ', $resultat['erreurs'])])
                ->withInput();
        }

        return redirect()
            ->route('admin.emplois-du-temps.indisponibilites.index')
            ->with('success', 'Déclaration enregistrée.');
    }

    public function edit(Request $request, ScheduleAvailability $plage): View
    {
        Gate::authorize('viewAny', Schedule::class);
        $this->autoriserPerimetre($request, $plage);

        return view('modules.schedule.indisponibilites.form', $this->donneesFormulaire($request) + [
            'plage' => $plage,
        ]);
    }

    public function update(UpdateScheduleAvailabilityRequest $request, ScheduleAvailability $plage): RedirectResponse
    {
        $this->autoriserPerimetre($request, $plage);

        $donnees = $request->validated();
        $this->autoriserRessource($request, $donnees);

        $resultat = $this->disponibilites->modifier($plage, $donnees);

        if (isset($resultat['erreurs'])) {
            return redirect()->back()
                ->withErrors(['conflit' => implode(' ', $resultat['erreurs'])])
                ->withInput();
        }

        return redirect()
            ->route('admin.emplois-du-temps.indisponibilites.index')
            ->with('success', 'Déclaration mise à jour.');
    }

    public function destroy(Request $request, ScheduleAvailability $plage): RedirectResponse
    {
        $this->autoriserPerimetre($request, $plage);

        $this->disponibilites->supprimer($plage);

        return redirect()
            ->route('admin.emplois-du-temps.indisponibilites.index')
            ->with('success', 'Déclaration supprimée.');
    }

    /**
     * Listes de référence du formulaire, scopées au périmètre faculté
     * (salles/groupes) — enseignants transversaux.
     *
     * @return array<string, mixed>
     */
    private function donneesFormulaire(Request $request): array
    {
        $faculteId = $request->user()->faculteRestreinteId();

        return [
            'enseignants' => Teacher::where('actif', true)->orderBy('nom')->get(),
            'salles' => Room::where('actif', true)
                ->when($faculteId !== null, fn ($q) => $q->where(fn ($r) => $r->where('faculte_id', $faculteId)->orWhereNull('faculte_id')))
                ->orderBy('code')->get(),
            'groupes' => Group::where('actif', true)
                ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
                ->orderBy('code')->get(),
            'annees' => AnneeUniversitaire::orderByDesc('date_debut')->get(),
            'jours' => ScheduleAvailability::JOURS,
            'types' => ScheduleAvailability::TYPES,
        ];
    }

    /**
     * Périmètre faculté : un gestionnaire restreint ne peut agir que sur
     * les plages salle/groupe de sa faculté (salles partagées sans faculté
     * = transversales, plages enseignant = transversales).
     */
    private function autoriserPerimetre(Request $request, ScheduleAvailability $plage): void
    {
        $faculteId = $request->user()->faculteRestreinteId();

        if ($faculteId === null) {
            return;
        }

        $horsPerimetre = match (true) {
            $plage->group_id !== null => $plage->group?->faculte_id !== $faculteId,
            $plage->room_id !== null => $plage->room?->faculte_id !== null && $plage->room->faculte_id !== $faculteId,
            default => false,
        };

        abort_if($horsPerimetre, 404);
    }

    /**
     * La ressource VISÉE par la requête est-elle dans le périmètre ?
     * (salles partagées sans faculté et enseignants = transversaux —
     * bloque toute requête forgée hors faculté).
     *
     * @param  array<string, mixed>  $donnees
     */
    private function autoriserRessource(Request $request, array $donnees): void
    {
        $faculteId = $request->user()->faculteRestreinteId();

        if ($faculteId === null) {
            return;
        }

        if (($donnees['group_id'] ?? null) !== null) {
            abort_unless(
                Group::whereKey($donnees['group_id'])->where('faculte_id', $faculteId)->exists(),
                404,
            );
        }

        if (($donnees['room_id'] ?? null) !== null) {
            abort_unless(
                Room::whereKey($donnees['room_id'])
                    ->where(fn ($q) => $q->where('faculte_id', $faculteId)->orWhereNull('faculte_id'))
                    ->exists(),
                404,
            );
        }
    }
}
