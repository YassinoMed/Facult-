<?php

namespace App\Modules\Schedule\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AnneeUniversitaire;
use App\Models\Matiere;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleChange;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use App\Modules\Schedule\Requests\DeplacerScheduleSlotRequest;
use App\Modules\Schedule\Requests\StoreScheduleExceptionRequest;
use App\Modules\Schedule\Requests\StoreScheduleRequest;
use App\Modules\Schedule\Requests\StoreScheduleSlotRequest;
use App\Modules\Schedule\Requests\UpdateScheduleSlotRequest;
use App\Modules\Schedule\Services\ScheduleService;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Back-office des emplois du temps : plannings, créneaux, exceptions,
 * historique. Contrôleur fin — conflits et cycle de vie en services.
 */
class ScheduleController extends Controller
{
    public function __construct(
        private readonly ScheduleService $plannings,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Schedule::class);

        $query = Schedule::with(['groupe.faculte', 'anneeUniversitaire'])->withCount('slots');

        if ($faculteId = $request->user()->faculteRestreinteId()) {
            $query->whereHas('groupe', fn ($q) => $q->where('faculte_id', $faculteId));
        } elseif ($filtre = $request->input('faculte_id')) {
            $query->whereHas('groupe', fn ($q) => $q->where('faculte_id', $filtre));
        }

        if ($semestre = $request->input('semestre')) {
            $query->where('semestre', $semestre);
        }

        return view('modules.schedule.index', [
            'plannings' => $query->latest()->paginate(12)->withQueryString(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('viewAny', Schedule::class);

        return view('modules.schedule.create', [
            'annees' => AnneeUniversitaire::orderByDesc('date_debut')->get(),
            'groupes' => Group::with('faculte')->orderBy('code')->get(),
        ]);
    }

    public function store(StoreScheduleRequest $request): RedirectResponse
    {
        $donnees = $request->validated();

        // Isolation faculté (audit sécurité 04/10/2026) : le groupe cible
        // doit relever du périmètre de l'utilisateur — scope → 404.
        abort_unless(
            $request->user()->peutAccederFaculte((int) Group::findOrFail($donnees['groupe_id'])->faculte_id),
            404
        );

        try {
            $planning = Schedule::create($donnees);
        } catch (UniqueConstraintViolationException) {
            return redirect()->back()
                ->withErrors(['groupe_id' => 'Un emploi du temps existe déjà pour ce groupe, cette année et ce semestre.'])
                ->withInput();
        }

        return redirect()->route('admin.emplois-du-temps.show', $planning)
            ->with('success', 'Emploi du temps créé.');
    }

    public function show(Request $request, Schedule $planning): View
    {
        $planning->loadMissing('groupe.faculte');
        abort_unless($request->user()->peutAccederFaculte((int) $planning->groupe->faculte_id), 404);
        Gate::authorize('view', $planning);

        $planning->load([
            'slots' => fn ($q) => $q->orderBy('day_of_week')->orderBy('start_time'),
            'slots.matiere', 'slots.teacher', 'slots.room',
            'slots.exceptions' => fn ($q) => $q->whereDate('exception_date', '>=', today())->orderBy('exception_date'),
        ]);

        return view('modules.schedule.show', [
            'planning' => $planning,
            'matieres' => Matiere::orderBy('code')->get(),
            'enseignants' => Teacher::where('actif', true)->orderBy('nom')->get(),
            'salles' => Room::where('actif', true)->orderBy('code')->get(),
            'controle' => $this->plannings->controlePublication($planning),
            'groupesDuplication' => Group::orderBy('code')
                ->when($request->user()->faculteRestreinteId(), fn ($q, $f) => $q->where('faculte_id', $f))->get(),
            'anneesDuplication' => AnneeUniversitaire::orderByDesc('date_debut')->get(),
            'historique' => ScheduleChange::whereHas(
                'slot',
                fn ($q) => $q->where('schedule_id', $planning->id)
            )->with('auteur')->latest()->limit(30)->get(),
        ]);
    }

    public function transiter(Request $request, Schedule $planning, string $cible): RedirectResponse
    {
        $planning->loadMissing('groupe.faculte');
        abort_unless($request->user()->peutAccederFaculte((int) $planning->groupe->faculte_id), 404);
        Gate::authorize('manage', $planning);

        try {
            match ($cible) {
                'publie' => $this->plannings->publierPlanning($planning, (int) $request->user()->id),
                'verrouille' => $this->plannings->verrouillerPlanning($planning),
                default => $this->plannings->transiterPlanning($planning, $cible),
            };
        } catch (DomainException $e) {
            return back()->withErrors(['planning' => $e->getMessage()]);
        }

        return back()->with('success', "Planning passé à « {$cible} ».");
    }

    public function dupliquer(Request $request, Schedule $planning): RedirectResponse
    {
        $planning->loadMissing('groupe.faculte');
        abort_unless($request->user()->peutAccederFaculte((int) $planning->groupe->faculte_id), 404);
        Gate::authorize('manage', $planning);

        $cible = $request->validate([
            'groupe_id' => ['required', 'exists:groups,id'],
            'annee_universitaire_id' => ['required', 'exists:annees_universitaires,id'],
            'semestre' => ['required', 'integer', 'min:1', 'max:2'],
            'confirmer' => ['nullable', 'boolean'],
        ]);

        $groupeCible = Group::findOrFail($cible['groupe_id']);
        abort_unless($request->user()->peutAccederFaculte((int) $groupeCible->faculte_id), 404);

        if (! $request->boolean('confirmer')) {
            return back()->with('apercu_duplication', $this->plannings->previsualiserDuplication($planning))->withInput();
        }

        try {
            $copie = $this->plannings->dupliquer($planning, $cible, (int) $request->user()->id);
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors(['groupe_id' => 'Un planning existe déjà pour cette cible.'])->withInput();
        }

        return redirect()->route('admin.emplois-du-temps.show', $copie)
            ->with('success', 'Planning dupliqué en brouillon (salles à réaffecter).');
    }

    public function storeSlot(StoreScheduleSlotRequest $request, Schedule $planning): RedirectResponse
    {
        abort_unless($request->user()->peutAccederFaculte((int) $planning->groupe->faculte_id), 404);
        Gate::authorize('manage', $planning);

        try {
            $resultat = $this->plannings->creerCreneau($planning, $request->validated(), $request->user()->id);
        } catch (DomainException $e) {
            return redirect()->back()->withErrors(['creneau' => $e->getMessage()])->withInput();
        }

        if (isset($resultat['erreurs'])) {
            return redirect()->back()->withErrors(['conflit' => implode(' ', $resultat['erreurs'])])->withInput();
        }

        return redirect()->route('admin.emplois-du-temps.show', $planning)
            ->with('success', 'Créneau ajouté.');
    }

    public function editSlot(Request $request, ScheduleSlot $slot): View
    {
        $slot->loadMissing('schedule.groupe');
        abort_unless($request->user()->peutAccederFaculte((int) $slot->schedule->groupe->faculte_id), 404);
        Gate::authorize('manageSlot', $slot);

        return view('modules.schedule.edit-slot', [
            'slot' => $slot->loadMissing('matiere'),
            'matieres' => Matiere::orderBy('code')->get(),
            'enseignants' => Teacher::where('actif', true)->orderBy('nom')->get(),
            'salles' => Room::where('actif', true)->orderBy('code')->get(),
        ]);
    }

    public function updateSlot(UpdateScheduleSlotRequest $request, ScheduleSlot $slot): RedirectResponse
    {
        $slot->loadMissing('schedule.groupe');
        abort_unless($request->user()->peutAccederFaculte((int) $slot->schedule->groupe->faculte_id), 404);
        Gate::authorize('manageSlot', $slot);

        // §16 : portée explicite — une modification ponctuelle ne doit
        // JAMAIS altérer le créneau semestriel par accident.
        if ($request->input('portee') === 'date') {
            return $this->updateSlotPonctuel($request, $slot);
        }

        $donnees = $request->validated();
        $motif = $donnees['motif'] ?? null;
        unset($donnees['motif']);

        try {
            $resultat = $this->plannings->modifierCreneau($slot, $donnees, $request->user()->id, $motif);
        } catch (DomainException $e) {
            return redirect()->back()->withErrors(['creneau' => $e->getMessage()])->withInput();
        }

        if (isset($resultat['erreurs'])) {
            return redirect()->back()->withErrors(['conflit' => implode(' ', $resultat['erreurs'])])->withInput();
        }

        return redirect()->route('admin.emplois-du-temps.show', $slot->schedule_id)
            ->with('success', 'Créneau modifié.');
    }

    /**
     * Tableau déplaçable (drag & drop + alternative modale/clavier) :
     * colonnes par jour, cartes déplaçables, vérification live.
     */
    public function tableau(Request $request, Schedule $planning): View
    {
        $planning->loadMissing('groupe.faculte');
        abort_unless($request->user()->peutAccederFaculte((int) $planning->groupe->faculte_id), 404);
        Gate::authorize('view', $planning);

        $planning->load([
            'slots' => fn ($q) => $q->orderBy('start_time'),
            'slots.matiere', 'slots.teacher', 'slots.room',
        ]);

        return view('modules.schedule.tableau', [
            'planning' => $planning,
            'jours' => ScheduleSlot::JOURS,
            'salles' => Room::where('actif', true)->orderBy('code')->get(),
            'peutDeplacer' => $request->user()->can('manage', $planning),
        ]);
    }

    /**
     * Vérification live avant déplacement (dry-run, aucune écriture) :
     * le navigateur affiche ✓/⚠, le serveur re-vérifie au déplacement.
     */
    public function verifierDeplacement(DeplacerScheduleSlotRequest $request, ScheduleSlot $slot): JsonResponse
    {
        $slot->loadMissing('schedule.groupe');

        $conflits = $this->plannings->conflitsPour(
            $slot,
            $request->input('day_of_week'),
            $request->input('start_time'),
            $request->input('end_time'),
            $request->input('room_id') !== null ? (int) $request->input('room_id') : null,
        );

        return response()->json([
            'disponible' => $conflits === [],
            'conflits' => $conflits,
        ]);
    }

    /**
     * Déplacement confirmé : transaction + conflits + audit (via
     * modifierCreneau). JSON si fetch, redirection sinon (progressive).
     */
    public function deplacer(DeplacerScheduleSlotRequest $request, ScheduleSlot $slot): JsonResponse|RedirectResponse
    {
        $slot->loadMissing('schedule.groupe');
        abort_unless($request->user()->peutAccederFaculte((int) $slot->schedule->groupe->faculte_id), 404);
        Gate::authorize('manageSlot', $slot);

        try {
            $resultat = $this->plannings->modifierCreneau(
                $slot,
                $request->validated(),
                $request->user()->id,
                $request->input('motif') ?? 'Déplacement depuis le tableau.',
            );
        } catch (DomainException $e) {
            // Même garde que les autres mutations (SCHEDULE-06) : planning
            // verrouillé entre le dry-run et la transaction = refus métier,
            // jamais 500.
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return redirect()->back()->withErrors(['creneau' => $e->getMessage()]);
        }

        if (isset($resultat['erreurs'])) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Conflit détecté : déplacement refusé.',
                    'conflits' => $resultat['erreurs'],
                ], 422);
            }

            return redirect()->back()
                ->withErrors(['conflit' => implode(' ', $resultat['erreurs'])])
                ->withInput();
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Créneau déplacé.',
                'slot' => [
                    'id' => $slot->id,
                    'day_of_week' => $slot->day_of_week,
                    'start_time' => $slot->start_time,
                    'end_time' => $slot->end_time,
                    'room' => $slot->room?->code,
                    'status' => $slot->status,
                ],
            ]);
        }

        return redirect()->route('admin.emplois-du-temps.tableau', $slot->schedule_id)
            ->with('success', 'Créneau déplacé.');
    }

    /**
     * Modification UNIQUEMENT pour une date : crée une exception, le
     * créneau récurrent reste intact. Seuls salle/horaire/enseignant/
     * statut sont transposables (matière/type/jour = récurrent seul).
     */
    private function updateSlotPonctuel(Request $request, ScheduleSlot $slot): RedirectResponse
    {
        $request->validate([
            'date_ponctuelle' => 'required|date|after_or_equal:today',
        ]);

        foreach (['matiere_id', 'type', 'day_of_week'] as $champ) {
            if ($request->filled($champ) && (string) $request->input($champ) !== (string) $slot->{$champ}) {
                return redirect()->back()
                    ->withErrors(['portee' => 'Matière, type et jour ne peuvent changer que pour tout le semestre.'])
                    ->withInput();
            }
        }

        try {
            $this->plannings->creerException($slot, [
                'exception_date' => $request->input('date_ponctuelle'),
                'new_start_time' => $request->input('start_time'),
                'new_end_time' => $request->input('end_time'),
                'new_room_id' => $request->input('room_id'),
                'new_teacher_id' => $request->input('teacher_id'),
                'status' => $request->input('status') === 'cancelled' ? 'cancelled' : 'modified',
                'reason' => $request->input('motif'),
            ], $request->user()->id);
        } catch (UniqueConstraintViolationException) {
            return redirect()->back()
                ->withErrors(['date_ponctuelle' => 'Une exception existe déjà pour ce créneau à cette date.'])
                ->withInput();
        } catch (DomainException $e) {
            return redirect()->back()
                ->withErrors(['creneau' => $e->getMessage()])
                ->withInput();
        }

        return redirect()->route('admin.emplois-du-temps.show', $slot->schedule_id)
            ->with('success', 'Exception ponctuelle enregistrée — le créneau semestriel est inchangé.');
    }

    public function destroySlot(Request $request, ScheduleSlot $slot): RedirectResponse
    {
        $slot->loadMissing('schedule.groupe');
        $planningId = $slot->schedule_id;
        abort_unless($request->user()->peutAccederFaculte((int) $slot->schedule->groupe->faculte_id), 404);
        Gate::authorize('manageSlot', $slot);

        try {
            $this->plannings->supprimerCreneau($slot, $request->user()->id, $request->input('motif'));
        } catch (DomainException $e) {
            return redirect()->back()->withErrors(['creneau' => $e->getMessage()]);
        }

        return redirect()->route('admin.emplois-du-temps.show', $planningId)
            ->with('success', 'Créneau supprimé.');
    }

    public function cancelSlot(Request $request, ScheduleSlot $slot): RedirectResponse
    {
        $slot->loadMissing('schedule.groupe');
        abort_unless($request->user()->peutAccederFaculte((int) $slot->schedule->groupe->faculte_id), 404);
        Gate::authorize('manageSlot', $slot);

        try {
            $this->plannings->annulerCreneau($slot, $request->user()->id, $request->input('motif'));
        } catch (DomainException $e) {
            return redirect()->back()->withErrors(['creneau' => $e->getMessage()]);
        }

        return redirect()->route('admin.emplois-du-temps.show', $slot->schedule_id)
            ->with('success', 'Créneau annulé.');
    }

    public function restoreSlot(Request $request, ScheduleSlot $slot): RedirectResponse
    {
        $slot->loadMissing('schedule.groupe');
        abort_unless($request->user()->peutAccederFaculte((int) $slot->schedule->groupe->faculte_id), 404);
        Gate::authorize('manageSlot', $slot);

        try {
            $this->plannings->restaurerCreneau($slot, $request->user()->id);
        } catch (DomainException $e) {
            return redirect()->back()->withErrors(['creneau' => $e->getMessage()]);
        }

        return redirect()->route('admin.emplois-du-temps.show', $slot->schedule_id)
            ->with('success', 'Créneau restauré.');
    }

    public function storeException(StoreScheduleExceptionRequest $request, ScheduleSlot $slot): RedirectResponse
    {
        $slot->loadMissing('schedule.groupe');
        abort_unless($request->user()->peutAccederFaculte((int) $slot->schedule->groupe->faculte_id), 404);
        Gate::authorize('manageSlot', $slot);

        try {
            $this->plannings->creerException($slot, $request->validated(), $request->user()->id);
        } catch (UniqueConstraintViolationException) {
            return redirect()->back()
                ->withErrors(['exception_date' => 'Une exception existe déjà pour ce créneau à cette date.'])
                ->withInput();
        } catch (DomainException $e) {
            return redirect()->back()
                ->withErrors(['creneau' => $e->getMessage()])
                ->withInput();
        }

        return redirect()->route('admin.emplois-du-temps.show', $slot->schedule_id)
            ->with('success', 'Exception enregistrée.');
    }
}
