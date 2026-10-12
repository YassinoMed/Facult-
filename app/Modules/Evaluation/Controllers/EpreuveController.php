<?php

namespace App\Modules\Evaluation\Controllers;

use App\Models\Matiere;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Models\Surveillance;
use App\Modules\Evaluation\Requests\StoreEpreuveRequest;
use App\Modules\Evaluation\Services\ConflitEpreuveService;
use App\Modules\Evaluation\Services\ConvocationService;
use App\Modules\Evaluation\Services\EpreuveService;
use App\Modules\Evaluation\Services\ExamRoomAssignmentService;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class EpreuveController
{
    public function index(Request $request): View
    {
        Gate::authorize('epreuves.view');

        // Isolation faculté (audit sécurité 04/10/2026) : un compte
        // restreint ne liste que son périmètre (transversal inclus).
        $epreuves = Epreuve::with(['matiere', 'salle', 'groupe', 'examen.session', 'surveillants'])
            ->when($faculteId = $request->user()->faculteRestreinteId(), fn ($q) => $q->pourFaculteId($faculteId))
            ->when($request->filled('session_id'), fn ($q) => $q->where('session_id', $request->input('session_id')))
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->input('statut')))
            ->when($request->filled('salle_id'), fn ($q) => $q->where('salle_id', $request->input('salle_id')))
            ->when($request->filled('date'), fn ($q) => $q->whereDate('date', $request->input('date')))
            ->orderBy('date')
            ->orderBy('heure_debut')
            ->paginate(20)
            ->withQueryString();

        return view('modules.evaluation.epreuves-index', [
            'epreuves' => $epreuves,
            'sessions' => SessionExamen::orderByDesc('date_debut')->get(),
            'salles' => Room::where('actif', true)->orderBy('code')->get(),
        ]);
    }

    /**
     * Planning visuel / calendrier des examens avec filtres avancés et alertes (§7).
     */
    public function planning(Request $request, ConflitEpreuveService $conflits): View
    {
        Gate::authorize('epreuves.view');

        $faculteId = $request->user()->faculteRestreinteId();

        $query = Epreuve::with(['matiere.ue.programme', 'salle', 'groupe', 'session', 'surveillants.enseignant', 'candidats'])
            ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
            ->when($request->filled('session_id'), fn ($q) => $q->where('session_id', $request->input('session_id')))
            ->when($request->filled('programme_id'), fn ($q) => $q->whereHas('matiere.ue.programme', fn ($p) => $p->where('id', $request->input('programme_id'))))
            // GO condition 3 : niveau dérivé de la chaîne LMD pour les
            // ECUE rattachées (legacy = standalone uniquement).
            ->when($request->filled('niveau'), function ($q) use ($request) {
                $niveau = (string) $request->input('niveau');

                $q->where(function ($w) use ($niveau) {
                    $w->whereHas('groupe', fn ($g) => $g->where('niveau', $niveau))
                        ->orWhereHas('matiere', fn ($m) => $m->whereNull('ue_id')->where('niveau', $niveau))
                        ->orWhereHas('matiere.ue.semestre.niveau', fn ($n) => $n->where('code', $niveau));
                });
            })
            ->when($request->filled('matiere_id'), fn ($q) => $q->where('matiere_id', $request->input('matiere_id')))
            ->when($request->filled('salle_id'), fn ($q) => $q->where('salle_id', $request->input('salle_id')))
            ->when($request->filled('teacher_id'), fn ($q) => $q->whereHas('surveillants', fn ($s) => $s->where('teacher_id', $request->input('teacher_id'))->where('statut', '!=', 'annule')))
            ->when($request->filled('date_debut'), fn ($q) => $q->whereDate('date', '>=', $request->input('date_debut')))
            ->when($request->filled('date_fin'), fn ($q) => $q->whereDate('date', '<=', $request->input('date_fin')));

        $epreuves = $query->orderBy('date')->orderBy('heure_debut')->get();

        // Regroupement par date pour le calendrier
        $parDate = $epreuves->groupBy(fn (Epreuve $e) => $e->date->format('Y-m-d'));

        return view('modules.evaluation.planning', [
            'parDate' => $parDate,
            'totalEpreuves' => $epreuves->count(),
            'sessions' => SessionExamen::orderByDesc('date_debut')->get(),
            'programmes' => Programme::when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))->orderBy('code')->get(),
            'matieres' => Matiere::where('actif', true)->orderBy('code')->get(),
            'salles' => Room::where('actif', true)->orderBy('code')->get(),
            'enseignants' => Teacher::where('actif', true)->orderBy('nom')->get(),
        ]);
    }

    public function show(Epreuve $epreuve): View
    {
        $this->verifierFaculte($epreuve);
        Gate::authorize('view', $epreuve);

        $epreuve->load([
            'matiere.ue',
            'salle',
            'groupe',
            'examen.session',
            'surveillants.enseignant',
            'candidats.etudiant',
            'candidats.salle',
            'incidents',
        ]);

        $faculteId = auth()->user()?->faculteRestreinteId();
        $enseignantsDisponibles = Teacher::where('actif', true)
            ->when($faculteId !== null, fn ($q) => $q->where(
                fn ($w) => $w->whereHas('departement', fn ($d) => $d->where('faculte_id', $faculteId))
                    ->orWhereNull('departement_id')
            ))
            ->orderBy('nom')->get();

        $jourSemaine = strtolower($epreuve->date->format('l'));
        $hDeb = substr((string) $epreuve->heure_debut, 0, 5);
        $hFin = substr((string) $epreuve->heure_fin, 0, 5);

        $slotsConflits = ScheduleSlot::where('day_of_week', $jourSemaine)
            ->where('status', '!=', 'cancelled')
            ->get()
            ->filter(fn ($s) => $s->chevauche($hDeb, $hFin))
            ->pluck('teacher_id')
            ->flip();

        $autresEpreuvesConflits = Surveillance::where('statut', '!=', 'annule')
            ->whereNotNull('teacher_id')
            ->where('epreuve_id', '!=', $epreuve->id)
            ->whereHas('epreuve', function ($q) use ($epreuve, $hDeb, $hFin) {
                $q->whereDate('date', $epreuve->date)
                    ->where('statut', '!=', 'cancelled')
                    ->where('heure_debut', '<', $hFin)
                    ->where('heure_fin', '>', $hDeb);
            })
            ->pluck('teacher_id')
            ->flip();

        $chargesSession = Surveillance::where('statut', '!=', 'annule')
            ->whereNotNull('teacher_id')
            ->whereHas('epreuve', fn ($q) => $q->where('session_id', $epreuve->session_id))
            ->selectRaw('teacher_id, count(*) as total')
            ->groupBy('teacher_id')
            ->pluck('total', 'teacher_id');

        foreach ($enseignantsDisponibles as $ens) {
            if (isset($slotsConflits[$ens->id]) || isset($autresEpreuvesConflits[$ens->id])) {
                $ens->statut_dispo = 'conflit';
            } elseif (($chargesSession[$ens->id] ?? 0) >= 4) {
                $ens->statut_dispo = 'charge_elevee';
            } else {
                $ens->statut_dispo = 'disponible';
            }
        }

        return view('modules.evaluation.epreuves-show', [
            'epreuve' => $epreuve,
            'sallesDisponibles' => Room::where('actif', true)->orderBy('code')->get(),
            'enseignantsDisponibles' => $enseignantsDisponibles,
        ]);
    }

    public function update(Request $request, Epreuve $epreuve, EpreuveService $service): RedirectResponse
    {
        $this->verifierFaculte($epreuve);
        Gate::authorize('update', $epreuve);

        $valides = $request->validate([
            'date' => ['nullable', 'date'],
            'heure_debut' => ['nullable', 'date_format:H:i'],
            'heure_fin' => ['nullable', 'date_format:H:i', 'after:heure_debut'],
            'salle_id' => ['nullable', 'exists:rooms,id'],
            'capacite_necessaire' => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            $service->modifier($epreuve, array_filter($valides, fn ($v) => $v !== null), (int) $request->user()->id);
        } catch (DomainException $e) {
            return back()->withErrors(['epreuve' => $e->getMessage()])->withInput();
        }

        return back()->with('succes', 'Épreuve mise à jour avec succès.');
    }

    public function transiter(Request $request, Epreuve $epreuve, string $cible, EpreuveService $service): RedirectResponse
    {
        $this->verifierFaculte($epreuve);
        Gate::authorize('update', $epreuve);

        try {
            $service->transiter($epreuve, $cible, (int) $request->user()->id);
        } catch (DomainException $e) {
            return back()->withErrors(['epreuve' => $e->getMessage()]);
        }

        return back()->with('succes', "Statut de l'épreuve passé à « {$cible} ».");
    }

    public function verrouiller(Request $request, Epreuve $epreuve, EpreuveService $service): RedirectResponse
    {
        $this->verifierFaculte($epreuve);
        Gate::authorize('update', $epreuve);

        $motif = $request->input('motif', 'Verrouillage officiel');

        try {
            $service->verrouiller($epreuve, (int) $request->user()->id, $motif);
        } catch (DomainException $e) {
            return back()->withErrors(['epreuve' => $e->getMessage()]);
        }

        return back()->with('succes', 'Épreuve verrouillée avec succès.');
    }

    /**
     * Page web interactive d'émargement (§13).
     */
    public function emargement(Epreuve $epreuve): View
    {
        $this->verifierFaculte($epreuve);
        Gate::authorize('view', $epreuve);

        $epreuve->load([
            'matiere',
            'salle',
            'groupe',
            'session',
            'candidats.etudiant',
            'candidats.salle',
        ]);

        return view('modules.evaluation.emargement', [
            'epreuve' => $epreuve,
            'candidats' => $epreuve->candidats->sortBy(fn ($c) => ($c->etudiant->nom ?? '').' '.($c->etudiant->prenom ?? ''))->values(),
        ]);
    }

    /**
     * Enregistrement interactif de l'émargement (§13).
     */
    public function enregistrerEmargement(Request $request, Epreuve $epreuve, EpreuveService $service): RedirectResponse
    {
        $this->verifierFaculte($epreuve);
        Gate::authorize('update', $epreuve);

        $valides = $request->validate([
            'presences' => ['required', 'array'],
            'presences.*.candidat_id' => ['required', 'integer'],
            'presences.*.statut' => ['required', 'in:present,absent,absent_justifie,absent_injustifie,excuse,retard'],
            'presences.*.justification' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $bilan = $service->enregistrerEmargement($epreuve, $valides['presences'], (int) $request->user()->id);
        } catch (DomainException $e) {
            return back()->withErrors(['emargement' => $e->getMessage()]);
        }

        return redirect()->route('admin.evaluations.epreuves.show', $epreuve)
            ->with('succes', "Émargement enregistré : {$bilan['presents']} présent(s), {$bilan['absents']} absent(s), {$bilan['retards']} retard(s).");
    }

    /**
     * Affectation ou répartition multi-salles pour l'épreuve (§8, §10).
     */
    public function repartirSalles(Request $request, Epreuve $epreuve, ExamRoomAssignmentService $assignmentService): RedirectResponse
    {
        $this->verifierFaculte($epreuve);
        Gate::authorize('update', $epreuve);

        $action = $request->input('action', 'appliquer'); // 'simuler' | 'appliquer' | 'unique'

        if ($action === 'unique') {
            $valides = $request->validate([
                'salle_id' => ['required', 'exists:rooms,id'],
            ]);

            try {
                $assignmentService->affecterSalle($epreuve, (int) $valides['salle_id'], (int) $request->user()->id);
            } catch (DomainException $e) {
                return back()->withErrors(['salle' => $e->getMessage()]);
            }

            return back()->with('succes', 'Salle principale affectée.');
        }

        $valides = $request->validate([
            'salles' => ['required', 'array', 'min:1'],
            'salles.*' => ['integer', 'exists:rooms,id'],
        ]);

        try {
            $simulation = $assignmentService->simulerRepartition($epreuve, $valides['salles']);

            if (! $simulation['valide']) {
                $messages = array_column($simulation['conflits'], 'message');

                return back()->withErrors(['repartition' => implode(' | ', $messages) ?: 'Capacité totale insuffisante.'])->withInput();
            }

            if ($action === 'simuler') {
                return back()->with('simulation_repartition', $simulation);
            }

            $count = $assignmentService->appliquerRepartition($epreuve, $simulation['repartition'], (int) $request->user()->id);
        } catch (DomainException $e) {
            return back()->withErrors(['repartition' => $e->getMessage()]);
        }

        return back()->with('succes', "Répartition réussie : {$count} candidat(s) assigné(s) aux salles.");
    }

    public function convocation(Epreuve $epreuve, ConvocationService $convocations): Response
    {
        $this->verifierFaculte($epreuve);
        Gate::authorize('view', $epreuve);

        return $convocations->listeEmargement($epreuve);
    }

    /**
     * Convocation individuelle (Phase 5 §29) : périmètre via
     * l'épreuve du candidat (même Gate que l'émargement).
     */
    public function convocationCandidat(Candidat $candidat, ConvocationService $convocations): Response
    {
        Gate::authorize('view', $candidat->epreuve);

        return $convocations->convocation($candidat);
    }

    public function store(StoreEpreuveRequest $request, Examen $examen, EpreuveService $service): RedirectResponse
    {
        Gate::authorize('create', $examen);

        // Isolation faculté (audit sécurité 04/10/2026) : la policy
        // `create` n'a pas de contexte — l'examen cible doit relever du
        // périmètre de l'utilisateur (scope → 404).
        $faculteId = $examen->faculteId();
        abort_unless($faculteId === null || $request->user()->peutAccederFaculte($faculteId), 404);

        try {
            $epreuve = $service->creer($examen, $request->validated());
        } catch (DomainException $e) {
            return back()->withErrors(['conflit' => $e->getMessage()])->withInput();
        }

        return redirect()->route('admin.evaluations.epreuves.show', $epreuve)
            ->with('succes', 'Épreuve planifiée.');
    }

    public function verifier(StoreEpreuveRequest $request, ConflitEpreuveService $conflits): RedirectResponse
    {
        Gate::authorize('epreuves.view');

        $donnees = $request->validated();

        $liste = $conflits->detecter([
            'date' => $donnees['date'],
            'heure_debut' => $donnees['heure_debut'],
            'heure_fin' => $donnees['heure_fin'],
            'salle_id' => $donnees['salle_id'] ?? null,
            'groupe_id' => $donnees['groupe_id'] ?? null,
        ]);

        if ($liste === []) {
            return back()->with('succes', 'Aucun conflit détecté.');
        }

        return back()->with('conflits', $liste)->withInput();
    }

    public function genererCandidats(Examen $examen, Epreuve $epreuve, EpreuveService $service): RedirectResponse
    {
        $this->verifierFaculte($epreuve);
        Gate::authorize('update', $epreuve);

        try {
            $crees = $service->genererCandidats($epreuve);
        } catch (DomainException $e) {
            return back()->withErrors(['candidats' => $e->getMessage()]);
        }

        return back()->with('succes', "{$crees} candidat(s) convoqué(s).");
    }

    private function verifierFaculte(Epreuve $epreuve): void
    {
        if ($epreuve->faculteId() !== null && request()->user()?->faculteRestreinteId() !== null
            && ! request()->user()->peutAccederFaculte($epreuve->faculteId())) {
            abort(404);
        }
    }
}
