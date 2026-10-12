<?php

namespace App\Modules\Evaluation\Controllers;

use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Models\Surveillance;
use App\Modules\Evaluation\Services\SurveillanceConstraintResolver;
use App\Modules\Evaluation\Services\SurveillanceEquilibrageService;
use App\Modules\Evaluation\Services\SurveillanceService;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Teacher;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Contrôleur unifié de Surveillance et Garde des DS et Examens (§7-16).
 */
class SurveillanceController
{
    public function index(Request $request, SurveillanceService $service): View
    {
        Gate::authorize('epreuves.view');

        $faculteId = $request->user()->faculteRestreinteId();

        $query = Surveillance::with(['epreuve.matiere', 'epreuve.session', 'epreuve.salle', 'enseignant', 'utilisateur', 'salle'])
            ->when($faculteId !== null, fn ($q) => $q->whereHas('epreuve', fn ($e) => $e->pourFaculteId($faculteId)))
            ->when($request->filled('session_id'), fn ($q) => $q->whereHas('epreuve', fn ($e) => $e->where('session_id', $request->input('session_id'))))
            ->when($request->filled('type_evaluation'), fn ($q) => $q->whereHas('epreuve', fn ($e) => $e->where('type_evaluation', $request->input('type_evaluation'))))
            ->when($request->filled('teacher_id'), fn ($q) => $q->where('teacher_id', $request->input('teacher_id')))
            ->when($request->filled('salle_id'), fn ($q) => $q->where('salle_id', $request->input('salle_id')))
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->input('statut')))
            ->when($request->filled('presence'), fn ($q) => $q->where('presence', $request->input('presence')))
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->input('role')))
            ->when($request->filled('date'), fn ($q) => $q->whereHas('epreuve', fn ($e) => $e->whereDate('date', $request->input('date'))));

        $surveillances = $query->latest('id')->paginate(25)->withQueryString();

        // Cockpit synthétique d'indicateurs (§15)
        $cockpit = $service->obtenirCockpit(
            $faculteId,
            $request->filled('session_id') ? (int) $request->input('session_id') : null,
            $request->filled('type_evaluation') ? (string) $request->input('type_evaluation') : null
        );

        return view('modules.evaluation.surveillances-index', [
            'surveillances' => $surveillances,
            'sessions' => SessionExamen::orderByDesc('date_debut')->get(),
            // Enseignants rattachés à la faculté (ou sans rattachement =
            // transversaux) et salles de la faculté (ou sans rattachement) :
            // jamais les ressources d'une autre faculté.
            'enseignants' => Teacher::where('actif', true)
                ->when($faculteId !== null, fn ($q) => $q->where(
                    fn ($w) => $w->whereHas('departement', fn ($d) => $d->where('faculte_id', $faculteId))
                        ->orWhereNull('departement_id')
                ))
                ->orderBy('nom')->get(),
            'salles' => Room::where('actif', true)
                ->when($faculteId !== null, fn ($q) => $q->where(
                    fn ($w) => $w->where('faculte_id', $faculteId)->orWhereNull('faculte_id')
                ))
                ->orderBy('code')->get(),
            'cockpit' => $cockpit,
        ]);
    }

    public function store(Request $request, Epreuve $epreuve, SurveillanceService $service): RedirectResponse
    {
        $this->verifierFaculte($epreuve);
        Gate::authorize('update', $epreuve);

        $donnees = $request->validate([
            'teacher_id' => ['nullable', 'exists:teachers,id'],
            'user_id' => ['nullable', 'exists:users,id'],
            'role' => ['nullable', 'in:principal,adjoint,surveillant,reserve'],
            'salle_id' => ['nullable', 'exists:rooms,id'],
        ]);

        try {
            $service->assigner($epreuve, $donnees, (int) $request->user()->id);
        } catch (DomainException $e) {
            return back()->withErrors(['surveillant' => $e->getMessage()])->withInput();
        }

        return back()->with('succes', 'Surveillant affecté.');
    }

    public function desaffecter(Request $request, Surveillance $surveillance, SurveillanceService $service): RedirectResponse
    {
        $this->verifierFaculte($surveillance->epreuve);
        Gate::authorize('update', $surveillance->epreuve);

        try {
            $service->desaffecter($surveillance, $request->input('motif'), (int) $request->user()->id);
        } catch (DomainException $e) {
            return back()->withErrors(['surveillant' => $e->getMessage()]);
        }

        return back()->with('succes', 'Surveillant désaffecté.');
    }

    public function confirmer(Request $request, Surveillance $surveillance, SurveillanceService $service): RedirectResponse
    {
        $this->verifierFaculte($surveillance->epreuve);
        Gate::authorize('update', $surveillance->epreuve);

        try {
            $service->enregistrerPresence($surveillance, 'present', null, null, null, (int) $request->user()->id);
        } catch (DomainException $e) {
            return back()->withErrors(['surveillant' => $e->getMessage()]);
        }

        return back()->with('succes', 'Surveillance confirmée.');
    }

    public function emargement(Request $request, Surveillance $surveillance, SurveillanceService $service): RedirectResponse
    {
        $this->verifierFaculte($surveillance->epreuve);
        Gate::authorize('update', $surveillance->epreuve);

        $valides = $request->validate([
            'presence' => ['required', 'in:present,retard,absent,absent_justifie'],
            'heure_arrivee' => ['nullable', 'date_format:H:i'],
            'heure_depart' => ['nullable', 'date_format:H:i'],
            'motif' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $service->enregistrerPresence(
                $surveillance,
                $valides['presence'],
                $valides['heure_arrivee'] ?? null,
                $valides['heure_depart'] ?? null,
                $valides['motif'] ?? null,
                (int) $request->user()->id
            );
        } catch (DomainException $e) {
            return back()->withErrors(['emargement' => $e->getMessage()]);
        }

        return back()->with('succes', 'Émargement du surveillant enregistré.');
    }

    public function remplacer(Request $request, Surveillance $surveillance, SurveillanceService $service): RedirectResponse
    {
        $this->verifierFaculte($surveillance->epreuve);
        Gate::authorize('update', $surveillance->epreuve);

        $donnees = $request->validate([
            'teacher_id' => ['nullable', 'exists:teachers,id'],
            'user_id' => ['nullable', 'exists:users,id'],
            'role' => ['nullable', 'in:principal,adjoint,surveillant,reserve'],
            'salle_id' => ['nullable', 'exists:rooms,id'],
            'motif' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $service->remplacer($surveillance, $donnees, $donnees['motif'] ?? null, (int) $request->user()->id);
        } catch (DomainException $e) {
            return back()->withErrors(['surveillant' => $e->getMessage()]);
        }

        return back()->with('succes', 'Surveillant remplacé avec succès.');
    }

    public function simuler(Request $request, Epreuve $epreuve, SurveillanceService $service): JsonResponse
    {
        $this->verifierFaculte($epreuve);
        Gate::authorize('update', $epreuve);

        $valides = $request->validate([
            'enseignants' => ['required', 'array'],
            'enseignants.*' => ['integer', 'exists:teachers,id'],
            'salle_id' => ['nullable', 'exists:rooms,id'],
        ]);

        $bilan = $service->simuler($epreuve, $valides['enseignants'], $valides['salle_id'] ?? null);

        return response()->json($bilan);
    }

    public function cockpit(Request $request, SurveillanceService $service, SurveillanceEquilibrageService $equilibrage, SurveillanceConstraintResolver $resolver): View
    {
        Gate::authorize('epreuves.view');

        $faculteId = $request->user()->faculteRestreinteId();
        $sessionId = $request->filled('session_id') ? (int) $request->input('session_id') : null;
        $typeEval = $request->filled('type_evaluation') ? (string) $request->input('type_evaluation') : null;

        $cockpit = $service->obtenirCockpit($faculteId, $sessionId, $typeEval);
        $charges = $service->calculerChargeEnseignants($sessionId, $faculteId);

        // Synthèse charge enseignants & couverture épreuves (§14)
        $totalEnseignants = $charges->count();
        $chargesArray = $charges->pluck('total_gardes')->all();
        $minCharge = $chargesArray !== [] ? min($chargesArray) : 0;
        $maxCharge = $chargesArray !== [] ? max($chargesArray) : 0;
        $moyenneCharge = $chargesArray !== [] ? round(array_sum($chargesArray) / count($chargesArray), 1) : 0.0;
        $ecartCharge = $maxCharge - $minCharge;
        $totalHeures = round($charges->sum('heures_totales'), 1);

        $epreuves = Epreuve::query()
            ->where('statut', '!=', 'cancelled')
            ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
            ->when($sessionId !== null, fn ($q) => $q->where('session_id', $sessionId))
            ->when($typeEval !== null, fn ($q) => $q->where('type_evaluation', $typeEval))
            ->with(['surveillants' => fn ($s) => $s->where('statut', '!=', 'annule'), 'salle', 'candidats.salle', 'groupe.etudiants'])
            ->get();

        // Besoins configurés via le moteur (source unique, point 4) : le
        // lot de salles est préchargé en 1 requête (zéro N+1).
        $contraintes = $resolver->resoudre($faculteId, null, $sessionId);
        $toutesSallesIds = $epreuves
            ->flatMap(fn (Epreuve $e) => array_merge([$e->salle_id], $e->candidats->pluck('salle_id')->all()))
            ->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $sallesMap = $toutesSallesIds === []
            ? collect()
            : Room::whereIn('id', $toutesSallesIds)->get(['id', 'code', 'capacite', 'min_surveillants', 'ratio_surveillants'])->keyBy('id');

        $totalEpreuves = $epreuves->count();
        $epreuvesCorrectes = 0;
        $epreuvesIncompletes = 0;
        $epreuvesSansGarde = 0;
        $epreuvesProblemeCapacite = 0;

        foreach ($epreuves as $epr) {
            $besoin = $equilibrage->evaluerBesoinsEpreuve($epr, $contraintes, $sallesMap);
            $nbActifs = $epr->surveillants->where('role', '!=', 'reserve')->count();
            $nbGardes = $epr->surveillants->where('role', 'reserve')->count();

            if ($nbActifs >= $besoin['besoin_surveillants_actifs'] && $nbGardes >= $besoin['besoin_gardes']) {
                $epreuvesCorrectes++;
            } else {
                $epreuvesIncompletes++;
            }

            if ($nbGardes === 0) {
                $epreuvesSansGarde++;
            }

            if (! $besoin['capacite_valide']) {
                $epreuvesProblemeCapacite++;
            }
        }

        $cockpit['total_enseignants'] = $totalEnseignants;
        $cockpit['charge_moyenne'] = $moyenneCharge;
        $cockpit['charge_min'] = $minCharge;
        $cockpit['charge_max'] = $maxCharge;
        $cockpit['ecart_charge'] = $ecartCharge;
        $cockpit['total_heures'] = $totalHeures;
        $cockpit['total_epreuves'] = $totalEpreuves;
        $cockpit['epreuves_correctes'] = $epreuvesCorrectes;
        $cockpit['epreuves_incompletes'] = $epreuvesIncompletes;
        $cockpit['epreuves_sans_garde'] = $epreuvesSansGarde;
        $cockpit['epreuves_probleme_capacite'] = $epreuvesProblemeCapacite;

        return view('modules.evaluation.surveillances-cockpit', [
            'cockpit' => $cockpit,
            'charges' => $charges,
            'sessions' => SessionExamen::orderByDesc('date_debut')->get(),
            'filtres' => [
                'session_id' => $sessionId,
                'type_evaluation' => $typeEval,
            ],
        ]);
    }

    /**
     * Espace personnel du surveillant / enseignant connecté (« Mes gardes », §16).
     * Protection stricte anti-IDOR/BOLA : uniquement ses propres gardes.
     */
    public function mesGardes(Request $request): View
    {
        $user = $request->user();

        // Récupération de l'enseignant associé par email si existant
        $teacher = Teacher::where('email', $user->email)->first();

        $gardes = Surveillance::with(['epreuve.matiere', 'epreuve.session', 'epreuve.salle', 'salle'])
            ->where(function ($q) use ($user, $teacher) {
                $q->where('user_id', $user->id);
                if ($teacher !== null) {
                    $q->orWhere('teacher_id', $teacher->id);
                }
            })
            ->where('evaluation_surveillants.statut', '!=', 'annule')
            ->join('evaluation_epreuves', 'evaluation_surveillants.epreuve_id', '=', 'evaluation_epreuves.id')
            ->where('evaluation_epreuves.statut', '!=', 'cancelled')
            ->orderBy('evaluation_epreuves.date')
            ->orderBy('evaluation_epreuves.heure_debut')
            ->select('evaluation_surveillants.*')
            ->paginate(20);

        return view('modules.evaluation.surveillances-mes-gardes', [
            'gardes' => $gardes,
            'enseignant' => $teacher,
        ]);
    }

    /**
     * Export CSV du planning et des gardes (§20).
     */
    public function exporter(Request $request): StreamedResponse
    {
        Gate::authorize('epreuves.view');

        $faculteId = $request->user()->faculteRestreinteId();

        $surveillances = Surveillance::with(['epreuve.matiere', 'epreuve.session', 'epreuve.salle', 'enseignant', 'utilisateur', 'salle'])
            ->when($faculteId !== null, fn ($q) => $q->whereHas('epreuve', fn ($e) => $e->pourFaculteId($faculteId)))
            ->when($request->filled('session_id'), fn ($q) => $q->whereHas('epreuve', fn ($e) => $e->where('session_id', $request->input('session_id'))))
            ->when($request->filled('type_evaluation'), fn ($q) => $q->whereHas('epreuve', fn ($e) => $e->where('type_evaluation', $request->input('type_evaluation'))))
            ->when($request->filled('teacher_id'), fn ($q) => $q->where('teacher_id', $request->input('teacher_id')))
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->input('statut')))
            ->when($request->filled('date_debut'), fn ($q) => $q->whereHas('epreuve', fn ($e) => $e->whereDate('date', '>=', $request->input('date_debut'))))
            ->when($request->filled('date_fin'), fn ($q) => $q->whereHas('epreuve', fn ($e) => $e->whereDate('date', '<=', $request->input('date_fin'))))
            ->where('statut', '!=', 'annule')
            ->get();

        $callback = function () use ($surveillances) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Date', 'Heure début', 'Heure fin', 'Type', 'Épreuve', 'Matière', 'Salle', 'Surveillant', 'Rôle', 'Statut', 'Présence', 'Heure arrivée']);

            foreach ($surveillances as $s) {
                fputcsv($handle, [
                    $s->id,
                    $s->epreuve?->date?->format('d/m/Y') ?? '—',
                    substr((string) ($s->epreuve?->heure_debut ?? ''), 0, 5),
                    substr((string) ($s->epreuve?->heure_fin ?? ''), 0, 5),
                    strtoupper((string) ($s->epreuve?->type_evaluation ?? 'EXAMEN')),
                    $s->epreuve?->code ?? '—',
                    $s->epreuve?->matiere?->nom ?? '—',
                    $s->salle?->code ?? $s->epreuve?->salle?->code ?? '—',
                    $s->libelleSurveillant(),
                    ucfirst($s->role),
                    $s->statut,
                    $s->presence ?? 'non_emarge',
                    $s->heure_arrivee ?? '—',
                ]);
            }

            fclose($handle);
        };

        $nomFichier = 'surveillances-'.now()->format('Y-m-d-His').'.csv';

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$nomFichier}\"",
        ]);
    }

    private function verifierFaculte(Epreuve $epreuve): void
    {
        if ($epreuve->faculteId() !== null && request()->user()?->faculteRestreinteId() !== null
            && ! request()->user()->peutAccederFaculte($epreuve->faculteId())) {
            abort(404);
        }
    }
}
