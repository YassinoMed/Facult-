<?php

namespace App\Modules\Schedule\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AnneeUniversitaire;
use App\Models\Matiere;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use App\Modules\Schedule\Services\ScheduleExplorerService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Exploration multi-axes des emplois du temps (back-office) : recherche
 * combinée, disponibilités, charge, conflits, occupation. Tout est
 * calculé serveur, scopé au périmètre faculté de l'utilisateur.
 */
class ScheduleExplorerController extends Controller
{
    public function __construct(
        private readonly ScheduleExplorerService $explorateur,
    ) {}

    /**
     * @return array{jours: array<int, string>, filtres: array<string, mixed>}
     */
    private function baseListes(Request $request): array
    {
        $faculteId = $request->user()->faculteRestreinteId();

        $groupes = Group::with('faculte')->where('actif', true)
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->orderBy('code')->get();

        return [
            'jours' => ScheduleSlot::JOURS,
            'enseignants' => Teacher::where('actif', true)->orderBy('nom')->get(),
            'salles' => Room::where('actif', true)->orderBy('code')->get(),
            'groupes' => $groupes,
            'matieres' => Matiere::orderBy('code')->get(),
            'annees' => AnneeUniversitaire::orderByDesc('date_debut')->get(),
            'filieres' => Group::where('actif', true)
                ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
                ->distinct()->orderBy('filiere')->pluck('filiere')->filter()->values(),
        ];
    }

    public function explorer(Request $request): View
    {
        $filtres = $request->validate([
            'enseignant_id' => 'nullable|integer|exists:teachers,id',
            'salle_id' => 'nullable|integer|exists:rooms,id',
            'groupe_id' => 'nullable|integer|exists:groups,id',
            'matiere_id' => 'nullable|integer|exists:matieres,id',
            'jour' => 'nullable|in:'.implode(',', ScheduleSlot::JOURS),
            'filiere' => 'nullable|string|max:150',
            'niveau' => 'nullable|in:L1,L2,L3,M1,M2,D1,D2,D3',
            'semestre' => 'nullable|integer|in:1,2',
            'annee_universitaire_id' => 'nullable|integer|exists:annees_universitaires,id',
            'vue' => 'nullable|in:semaine,jour',
            'jour_focus' => 'nullable|in:'.implode(',', ScheduleSlot::JOURS),
            'semaine' => 'nullable|integer|min:-52|max:52',
        ]);

        // IDOR : un groupe hors périmètre ne filtre jamais silencieusement.
        if (! empty($filtres['groupe_id'])) {
            $groupe = Group::findOrFail($filtres['groupe_id']);
            abort_unless($request->user()->peutAccederFaculte((int) $groupe->faculte_id), 404);
        }

        $filtres = array_filter($filtres, fn ($v) => $v !== null && $v !== '');
        $creneaux = $this->explorateur->creneauxFiltres($request->user(), $this->mapperFiltres($filtres));

        $decalage = (int) ($filtres['semaine'] ?? 0);
        $lundi = Carbon::now()->startOfWeek(Carbon::MONDAY)->addWeeks($decalage);

        return view('modules.schedule.explorer', array_merge($this->baseListes($request), [
            'creneaux' => $creneaux,
            'filtres' => $filtres,
            'lundi' => $lundi,
            'decalage' => $decalage,
            'aujourdhui' => Carbon::today()->toDateString(),
            'maintenant' => Carbon::now()->format('H:i'),
        ]));
    }

    public function disponibilites(Request $request): View
    {
        $valide = $request->validate([
            'jour' => 'nullable|in:'.implode(',', ScheduleSlot::JOURS),
            'debut' => 'nullable|date_format:H:i',
            'fin' => 'nullable|date_format:H:i|after:debut',
            'capacite_min' => 'nullable|integer|min:1|max:10000',
        ]);

        $jour = $valide['jour'] ?? strtolower(Carbon::now()->englishDayOfWeek);
        $debut = $valide['debut'] ?? '10:00';
        $fin = $valide['fin'] ?? '12:00';

        $dispo = $this->explorateur->disponibilites(
            $jour, $debut, $fin, $request->user()->faculteRestreinteId()
        );

        $sallesLibres = isset($valide['capacite_min'])
            ? $this->explorateur->sallesLibres($jour, $debut, $fin, (int) $valide['capacite_min'])
            : collect();

        return view('modules.schedule.disponibilites', array_merge($this->baseListes($request), [
            'jour' => $jour, 'debut' => $debut, 'fin' => $fin,
            'capaciteMin' => $valide['capacite_min'] ?? null,
            'enseignants' => $dispo['enseignants'], 'salles' => $dispo['salles'],
            'sallesLibres' => $sallesLibres,
        ]));
    }

    public function trouverCreneau(Request $request): View
    {
        $valide = $request->validate([
            'groupe_id' => 'nullable|integer|exists:groups,id',
            'enseignant_id' => 'nullable|integer|exists:teachers,id',
            'duree' => 'nullable|integer|in:60,90,120,180,240',
            'salle_id' => 'nullable|integer|exists:rooms,id',
        ]);

        $libres = [];
        if (! empty($valide['groupe_id'])) {
            $groupe = Group::findOrFail($valide['groupe_id']);
            abort_unless($request->user()->peutAccederFaculte((int) $groupe->faculte_id), 404);

            $libres = $this->explorateur->creneauxLibres(
                (int) $valide['groupe_id'],
                isset($valide['enseignant_id']) ? (int) $valide['enseignant_id'] : null,
                (int) ($valide['duree'] ?? 120),
                isset($valide['salle_id']) ? (int) $valide['salle_id'] : null,
            );
        }

        return view('modules.schedule.trouver-creneau', array_merge($this->baseListes($request), [
            'choix' => $valide,
            'libres' => $libres,
        ]));
    }

    public function charge(Request $request): View
    {
        return view('modules.schedule.charge', [
            'charges' => $this->explorateur->chargeEnseignants($request->user()->faculteRestreinteId()),
        ]);
    }

    public function conflits(Request $request): View
    {
        $type = $request->validate([
            'type' => 'nullable|in:tous,salle,enseignant,groupe',
        ])['type'] ?? 'tous';

        return view('modules.schedule.conflits', [
            'conflits' => $this->explorateur->conflitsDetectes($request->user()->faculteRestreinteId(), $type),
            'type' => $type,
        ]);
    }

    public function occupation(Request $request): View
    {
        $jour = $request->validate([
            'jour' => 'nullable|in:'.implode(',', ScheduleSlot::JOURS),
        ])['jour'] ?? strtolower(Carbon::now()->englishDayOfWeek);

        if (! in_array($jour, ScheduleSlot::JOURS, true)) {
            $jour = 'monday';
        }

        return view('modules.schedule.occupation', array_merge(
            ['jour' => $jour, 'jours' => ScheduleSlot::JOURS],
            $this->explorateur->occupationSalles($request->user()->faculteRestreinteId(), $jour),
        ));
    }

    /**
     * @param  array<string, mixed>  $filtres
     * @return array<string, mixed>
     */
    private function mapperFiltres(array $filtres): array
    {
        return [
            'teacher_id' => $filtres['enseignant_id'] ?? null,
            'room_id' => $filtres['salle_id'] ?? null,
            'groupe_id' => $filtres['groupe_id'] ?? null,
            'matiere_id' => $filtres['matiere_id'] ?? null,
            'day_of_week' => $filtres['jour'] ?? null,
            'filiere' => $filtres['filiere'] ?? null,
            'niveau' => $filtres['niveau'] ?? null,
            'semestre' => $filtres['semestre'] ?? null,
            'annee_universitaire_id' => $filtres['annee_universitaire_id'] ?? null,
        ];
    }
}
