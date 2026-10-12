<?php

namespace App\Modules\Evaluation\Controllers;

use App\Models\AnneeUniversitaire;
use App\Models\Matiere;
use App\Modules\Evaluation\Jobs\AffectationSurveillanceJob;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Models\Surveillance;
use App\Modules\Evaluation\Models\SurveillanceAssignmentRun;
use App\Modules\Evaluation\Services\ConflitEpreuveService;
use App\Modules\Evaluation\Services\SurveillanceConstraintResolver;
use App\Modules\Evaluation\Services\SurveillanceEquilibrageService;
use App\Modules\Evaluation\Services\SurveillanceService;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Gestion unifiée de l'affectation des enseignants aux examens et épreuves (§7-16).
 * Réutilise l'architecture Evaluation, SurveillanceService et SurveillanceEquilibrageService.
 */
class AffectationEnseignantController
{
    /**
     * Page principale : tableau des examens avec KPIs, filtres multicritères et état des affectations.
     */
    public function index(Request $request, SurveillanceEquilibrageService $equilibrage, ConflitEpreuveService $conflitService): View
    {
        Gate::authorize('epreuves.view');

        $faculteId = $request->user()->faculteRestreinteId();

        $query = Epreuve::query()
            ->where('statut', '!=', 'cancelled')
            ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId));

        // 1. Filtres multicritères
        if ($request->filled('annee_universitaire_id')) {
            $query->where('annee_universitaire_id', (int) $request->input('annee_universitaire_id'));
        }

        if ($request->filled('session_id')) {
            $query->where('session_id', (int) $request->input('session_id'));
        }

        if ($request->filled('semestre')) {
            $semestre = (int) $request->input('semestre');
            $query->whereHas('matiere', fn ($q) => $q->where('semestre', $semestre));
        }

        if ($request->filled('niveau')) {
            $niveau = (string) $request->input('niveau');
            $query->where(function ($q) use ($niveau) {
                $q->whereHas('groupe', fn ($g) => $g->where('niveau', $niveau))
                    ->orWhereHas('matiere', fn ($m) => $m->where('niveau', $niveau));
            });
        }

        if ($request->filled('groupe_id')) {
            $query->where('groupe_id', (int) $request->input('groupe_id'));
        }

        if ($request->filled('matiere_id')) {
            $query->where('matiere_id', (int) $request->input('matiere_id'));
        }

        if ($request->filled('teacher_id')) {
            $teacherId = (int) $request->input('teacher_id');
            $query->whereHas('surveillants', fn ($q) => $q->where('teacher_id', $teacherId)->whereIn('statut', ['assigne', 'confirme']));
        }

        if ($request->filled('date')) {
            $query->whereDate('date', $request->input('date'));
        }

        if ($request->filled('q') || $request->filled('recherche')) {
            $terme = trim((string) ($request->input('q') ?: $request->input('recherche')));
            $query->where(function ($q) use ($terme) {
                $q->where('code', 'like', "%{$terme}%")
                    ->orWhereHas('matiere', fn ($m) => $m->where('code', 'like', "%{$terme}%")->orWhere('nom', 'like', "%{$terme}%"))
                    ->orWhereHas('examen', fn ($e) => $e->where('libelle', 'like', "%{$terme}%"))
                    ->orWhereHas('groupe', fn ($g) => $g->where('code', 'like', "%{$terme}%")->orWhere('nom', 'like', "%{$terme}%"));
            });
        }

        // Filtre par enseignant affecté
        if ($request->filled('teacher_id')) {
            $teacherId = (int) $request->input('teacher_id');
            $query->whereHas('surveillants', fn ($s) => $s->where('teacher_id', $teacherId)->whereIn('statut', ['assigne', 'confirme']));
        }

        // Filtre par statut d'affectation ou couverture
        $statut = $request->input('statut_affectation') ?: $request->input('couverture');
        if (! empty($statut)) {
            if ($statut === 'non_affecte' || $statut === 'sans_surveillant') {
                $query->whereDoesntHave('surveillants', fn ($q) => $q->whereIn('statut', ['assigne', 'confirme']));
            } elseif ($statut === 'affecte') {
                $query->whereHas('surveillants', fn ($q) => $q->whereIn('statut', ['assigne', 'confirme'])->whereIn('role', ['principal', 'adjoint', 'surveillant']), '>=', 2)
                    ->whereHas('surveillants', fn ($q) => $q->whereIn('statut', ['assigne', 'confirme'])->where('role', 'reserve'), '>=', 1);
            } elseif ($statut === 'partiellement') {
                $query->whereHas('surveillants', fn ($q) => $q->whereIn('statut', ['assigne', 'confirme']))
                    ->where(function ($q) {
                        $q->whereDoesntHave('surveillants', fn ($s) => $s->whereIn('statut', ['assigne', 'confirme'])->whereIn('role', ['principal', 'adjoint', 'surveillant']), '>=', 2)
                            ->orWhereDoesntHave('surveillants', fn ($s) => $s->whereIn('statut', ['assigne', 'confirme'])->where('role', 'reserve'), '>=', 1);
                    });
            }
        }

        // 2. Calcul des KPIs globaux du périmètre
        $baseKpiQuery = clone $query;
        $totalExamens = (clone $baseKpiQuery)->count();
        $examensSansEnseignant = (clone $baseKpiQuery)
            ->whereDoesntHave('surveillants', fn ($q) => $q->whereIn('statut', ['assigne', 'confirme']))
            ->count();
        $examensAffectes = (clone $baseKpiQuery)
            ->whereHas('surveillants', fn ($q) => $q->whereIn('statut', ['assigne', 'confirme'])->whereIn('role', ['principal', 'adjoint', 'surveillant']), '>=', 2)
            ->whereHas('surveillants', fn ($q) => $q->whereIn('statut', ['assigne', 'confirme'])->where('role', 'reserve'), '>=', 1)
            ->count();

        $enseignantsDisposCount = Teacher::where('actif', true)
            ->when($faculteId !== null, fn ($q) => $q->where(
                fn ($w) => $w->whereHas('departement', fn ($d) => $d->where('faculte_id', $faculteId))
                    ->orWhereNull('departement_id')
            ))
            ->count();

        $epreuvesIdsInScope = (clone $baseKpiQuery)->pluck('id');
        $totalAffectations = Surveillance::whereIn('statut', ['assigne', 'confirme'])
            ->whereIn('epreuve_id', $epreuvesIdsInScope)
            ->count();

        // 3. Eager loading sans N+1 pour le tableau paginé
        $epreuves = $query
            ->with([
                'matiere.ue.programme',
                'examen.session',
                'session',
                'salle',
                'groupe.etudiants',
                'candidats.salle',
                'surveillants.enseignant',
                'surveillants.salle',
            ])
            ->withCount(['candidats'])
            ->orderBy('date')
            ->orderBy('heure_debut')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        // Préchargement groupé pour la détection des conflits (zéro N+1)
        $teacherIds = $epreuves->getCollection()
            ->pluck('surveillants')
            ->flatten()
            ->pluck('teacher_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $epreuveDates = $epreuves->getCollection()
            ->pluck('date')
            ->filter()
            ->map(fn ($d) => $d instanceof CarbonInterface ? $d->toDateString() : (string) $d)
            ->unique()
            ->values()
            ->all();

        $joursSemaine = $epreuves->getCollection()
            ->pluck('date')
            ->filter()
            ->map(fn ($d) => strtolower($d instanceof CarbonInterface ? $d->format('l') : Carbon::parse($d)->format('l')))
            ->unique()
            ->values()
            ->all();

        $slotsParEnseignant = ! empty($teacherIds)
            ? ScheduleSlot::whereIn('teacher_id', $teacherIds)
                ->whereIn('day_of_week', $joursSemaine)
                ->where('status', '!=', 'cancelled')
                ->with('matiere')
                ->get()
                ->groupBy('teacher_id')
            : collect();

        $autresEpreuvesParEnseignant = (! empty($teacherIds) && ! empty($epreuveDates))
            ? Epreuve::whereIn('date', $epreuveDates)
                ->where('statut', '!=', 'cancelled')
                ->whereHas('surveillants', fn ($s) => $s->whereIn('teacher_id', $teacherIds)->whereIn('statut', ['assigne', 'confirme']))
                ->with(['matiere', 'surveillants' => fn ($s) => $s->whereIn('teacher_id', $teacherIds)->whereIn('statut', ['assigne', 'confirme'])])
                ->get()
            : collect();

        // 4. Enrichissement en mémoire pour chaque épreuve (besoins, conflits, statut visuel)
        $conflitsDetectesCount = 0;
        foreach ($epreuves as $epreuve) {
            $besoins = $equilibrage->evaluerBesoinsEpreuve($epreuve);
            $conflits = $this->analyserConflitsEpreuve($epreuve, $slotsParEnseignant, $autresEpreuvesParEnseignant);

            if ($conflits !== []) {
                $conflitsDetectesCount++;
            }

            $actifsAssignes = $epreuve->surveillants
                ->whereIn('statut', ['assigne', 'confirme'])
                ->whereIn('role', ['principal', 'adjoint', 'surveillant'])
                ->count();

            $gardesAssignes = $epreuve->surveillants
                ->whereIn('statut', ['assigne', 'confirme'])
                ->where('role', 'reserve')
                ->count();

            $aPrincipal = $epreuve->surveillants
                ->whereIn('statut', ['assigne', 'confirme'])
                ->where('role', 'principal')
                ->isNotEmpty();

            // Détermination du statut visuel
            if ($conflits !== []) {
                $statutVisuel = 'conflit';
            } elseif ($actifsAssignes >= $besoins['besoin_surveillants_actifs'] && $gardesAssignes >= $besoins['besoin_gardes'] && $aPrincipal) {
                $statutVisuel = 'affecte';
            } elseif ($actifsAssignes > 0 || $gardesAssignes > 0) {
                $statutVisuel = 'partiellement';
            } else {
                $statutVisuel = 'non_affecte';
            }

            // Statut 'a_verifier' si sans Principal mais avec des surveillants, ou capacité limite
            if ($statutVisuel !== 'conflit' && $actifsAssignes > 0 && ! $aPrincipal) {
                $statutVisuel = 'a_verifier';
                $conflits[] = [
                    'type' => 'principal_manquant',
                    'message' => 'Aucun surveillant Principal n’a été désigné pour cette épreuve.',
                ];
            }

            $epreuve->besoins_calcules = $besoins;
            $epreuve->conflits_calcules = $conflits;
            $epreuve->statut_visuel = $statutVisuel;
            $epreuve->actifs_count = $actifsAssignes;
            $epreuve->gardes_count = $gardesAssignes;
        }

        // Si le filtre demande 'conflit' ou 'a_verifier', on filtre la collection
        if ($request->input('statut_affectation') === 'conflit') {
            $conflitsDetectesCount = $epreuves->where('statut_visuel', 'conflit')->count();
        }

        // 5. Référentiels pour les filtres
        $annees = AnneeUniversitaire::orderByDesc('date_debut')->get();
        $sessions = SessionExamen::withCount([
            'epreuves' => fn ($q) => $q->where('statut', '!=', 'cancelled')->when($faculteId !== null, fn ($sub) => $sub->pourFaculteId($faculteId)),
        ])->orderByDesc('date_debut')->get();
        $groupes = Group::where('actif', true)
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->orderBy('code')->get();
        $matieres = Matiere::orderBy('code')->get();
        $enseignants = Teacher::where('actif', true)
            ->when($faculteId !== null, fn ($q) => $q->where(
                fn ($w) => $w->whereHas('departement', fn ($d) => $d->where('faculte_id', $faculteId))
                    ->orWhereNull('departement_id')
            ))
            ->orderBy('nom')->orderBy('prenom')->get();
        $salles = Room::where('actif', true)
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId)->orWhereNull('faculte_id'))
            ->orderBy('code')->get();

        $activeRun = null;
        if ($request->filled('session_id')) {
            $activeRun = SurveillanceAssignmentRun::actifPour((int) $request->input('session_id'), $faculteId);
        }

        return view('modules.evaluation.affectations-index', [
            'epreuves' => $epreuves,
            'kpis' => [
                'total_examens' => $totalExamens,
                'examens_sans_enseignant' => $examensSansEnseignant,
                'examens_affectes' => $examensAffectes,
                'enseignants_disponibles' => $enseignantsDisposCount,
                'total_affectations' => $totalAffectations,
                'conflits_detectes' => $conflitsDetectesCount,
            ],
            'annees' => $annees,
            'sessions' => $sessions,
            'groupes' => $groupes,
            'matieres' => $matieres,
            'enseignants' => $enseignants,
            'salles' => $salles,
            'filtres' => $request->all(),
            'activeRun' => $activeRun,
        ]);
    }

    /**
     * Affectation manuelle d'un enseignant à une épreuve avec contrôles stricts de conflit.
     */
    public function assigner(Request $request, Epreuve $epreuve, SurveillanceService $service): RedirectResponse
    {
        $this->verifierFaculte($epreuve);
        Gate::authorize('surveillances.assign');

        $donnees = $request->validate([
            'teacher_id' => ['required', 'exists:teachers,id'],
            'role' => ['required', 'in:principal,adjoint,surveillant,reserve'],
            'salle_id' => ['nullable', 'exists:rooms,id'],
        ]);

        try {
            $service->assigner($epreuve, $donnees, (int) $request->user()->id);
        } catch (DomainException $e) {
            return back()->withErrors(['affectation' => $e->getMessage()])->withInput();
        }

        return back()->with('succes', 'Enseignant affecté avec succès à l’épreuve.');
    }

    /**
     * Retrait / désaffectation d'un enseignant d'une épreuve.
     */
    public function retirer(Request $request, Surveillance $surveillance, SurveillanceService $service): RedirectResponse
    {
        $this->verifierFaculte($surveillance->epreuve);
        Gate::authorize('surveillances.manage');

        try {
            $service->desaffecter($surveillance, 'Retiré depuis la gestion des affectations', (int) $request->user()->id);
        } catch (DomainException $e) {
            return back()->withErrors(['affectation' => $e->getMessage()]);
        }

        return back()->with('succes', 'Affectation retirée avec succès.');
    }

    /**
     * ⚡ Affectation automatique asynchrone : crée un suivi persistant puis
     * délègue au Job (file Laravel). La requête ne calcule rien elle-même.
     * Sous driver `sync` (tests), le Job s'exécute inline : même résultat.
     */
    public function autoAssigner(Request $request): RedirectResponse|JsonResponse
    {
        Gate::authorize('surveillances.assign');

        $donnees = $request->validate([
            'session_id' => ['required', 'exists:evaluation_sessions,id'],
        ], [
            'session_id.required' => 'Veuillez sélectionner une session d’examen.',
            'session_id.exists' => 'Session d’examen introuvable.',
        ]);

        $session = SessionExamen::findOrFail((int) $donnees['session_id']);
        $faculteId = $request->user()->faculteRestreinteId();

        // 1. Idempotence : un seul traitement actif par session × faculté.
        $runActif = SurveillanceAssignmentRun::actifPour($session->id, $faculteId);

        if ($runActif !== null) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Un traitement d’affectation est déjà en cours pour cette session.',
                    'run' => $runActif->progression(),
                ], 409);
            }

            return redirect()->route('admin.evaluations.affectations.runs', ['run' => $runActif->uuid])
                ->with('warning', 'Un traitement d’affectation est déjà en cours pour cette session.');
        }

        // 2. Garde-fou immédiat : session sans épreuve (le Job refuse aussi,
        // via la simulation, mais sansDb d'écriture inutile ici).
        $nbEpreuves = Epreuve::where('session_id', $session->id)
            ->where('statut', '!=', 'cancelled')
            ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
            ->count();

        if ($nbEpreuves === 0) {
            return back()->withErrors(['affectation' => 'Aucune épreuve planifiée dans cette session.']);
        }

        // 3. Snapshot de configuration résolu et figé pour le run (§9)
        $resolver = app(SurveillanceConstraintResolver::class);
        $contraintes = $resolver->resoudrePourSession($session, $faculteId);
        $configSnapshot = array_merge(
            $contraintes->versParams(),
            ['figee_le' => now()->toIso8601String()]
        );

        // 4. Création du suivi persistant puis dispatch en arrière-plan.
        $run = SurveillanceAssignmentRun::create([
            'uuid' => (string) Str::uuid(),
            'session_examen_id' => $session->id,
            'faculte_id' => $faculteId,
            'user_id' => $request->user()->id,
            'status' => SurveillanceAssignmentRun::STATUS_PENDING,
            'mode' => 'auto',
            'progress' => 0,
            'total_epreuves' => $nbEpreuves,
            'epreuves_traitees' => 0,
            'affectations_creees' => 0,
            'affectations_modifiees' => 0,
            'conflits' => 0,
            'erreurs' => 0,
            'config_snapshot' => $configSnapshot,
        ]);

        try {
            AffectationSurveillanceJob::dispatch($session->id, $faculteId, $request->user()->id, $run->id, 'auto');
        } catch (\Throwable $e) {
            report($e);
            Log::warning('affectation.dispatch_echec', ['run_id' => $run->id, 'session_id' => $session->id]);

            return redirect()->route('admin.evaluations.affectations.runs', ['run' => $run->uuid])
                ->with('error', 'Le lancement a échoué : le traitement est marqué en échec, vous pouvez le relancer.');
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Affectation automatique lancée en arrière-plan.',
                'run' => $run->fresh()->progression(),
            ], 202);
        }

        return redirect()->route('admin.evaluations.affectations.runs', ['run' => $run->uuid])
            ->with('succes', 'Affectation automatique lancée en arrière-plan. Vous pouvez continuer à utiliser l’application.');
    }

    /**
     * Optimisation globale des affectations (rééquilibrage des charges et présences).
     * Voie synchrone conservée : séances de taille courante, retour immédiat.
     */
    public function optimiser(Request $request, SurveillanceEquilibrageService $equilibrage): RedirectResponse
    {
        Gate::authorize('surveillances.manage');

        $donnees = $request->validate([
            'session_id' => ['required', 'exists:evaluation_sessions,id'],
        ], [
            'session_id.required' => 'Veuillez sélectionner une session d’examen.',
            'session_id.exists' => 'Session d’examen introuvable.',
        ]);

        $session = SessionExamen::findOrFail((int) $donnees['session_id']);
        $faculteId = $request->user()->faculteRestreinteId();

        try {
            // L'optimisation DÉPLACE des affectations existantes déjà
            // persistées (rééquilibrage réel) : ce n'est pas un simple
            // comblement des manques comme l'affectation automatique.
            $resultat = $equilibrage->optimiserSession($session, ['faculte_id' => $faculteId], (int) $request->user()->id);
        } catch (DomainException $e) {
            Log::warning('affectation.optimiser_echec', [
                'session_id' => $session->id,
                'user_id' => $request->user()->id,
                'message' => $e->getMessage(),
            ]);

            return back()->withErrors(['affectation' => $e->getMessage()]);
        }

        $message = "Optimisation globale appliquée : {$resultat['analysees']} épreuve(s) analysée(s), {$resultat['ameliorees']} affectation(s) améliorée(s). "
            .'Charge maximale : '.$resultat['charge_max'].' · Écart de charge : '.$resultat['ecart_avant'].' → '.$resultat['ecart_apres'].'.';

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'redirect' => route('admin.evaluations.affectations', ['session_id' => $session->id]),
            ]);
        }

        return back()->with('succes', $message);
    }

    /**
     * Page de suivi des traitements d'affectation (historique + direct).
     */
    public function runs(Request $request): View
    {
        Gate::authorize('epreuves.view');

        $faculteId = $request->user()->faculteRestreinteId();

        $query = SurveillanceAssignmentRun::with('session')
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->orderByDesc('id');

        if ($request->filled('session_id')) {
            $query->where('session_examen_id', (int) $request->input('session_id'));
        }

        $runs = $query->paginate(15)->withQueryString();

        $misEnAvant = null;
        if ($request->filled('run')) {
            $misEnAvant = SurveillanceAssignmentRun::where('uuid', (string) $request->input('run'))->first();
            if ($misEnAvant !== null) {
                $this->verifierRun($request, $misEnAvant);
            }
        }

        return view('modules.evaluation.affectations-runs', [
            'runs' => $runs,
            'misEnAvant' => $misEnAvant,
            'sessions' => SessionExamen::orderByDesc('date_debut')->get(),
        ]);
    }

    /**
     * Annulation coopérative d'un traitement (prise en compte entre deux épreuves).
     */
    public function annulerRun(Request $request, SurveillanceAssignmentRun $run): RedirectResponse
    {
        $this->verifierRun($request, $run);
        Gate::authorize('surveillances.manage');

        if (! in_array($run->status, [SurveillanceAssignmentRun::STATUS_PENDING, SurveillanceAssignmentRun::STATUS_RUNNING], true)) {
            return back()->withErrors(['run' => 'Seul un traitement en attente ou en cours peut être annulé.']);
        }

        $run->update(['status' => SurveillanceAssignmentRun::STATUS_CANCELLED, 'finished_at' => now()]);

        return back()->with('succes', "Traitement {$run->uuid} annulé : arrêt pris en compte à la prochaine épreuve.");
    }

    /**
     * Compteurs de confirmation pour la modale de lancement (requête légère).
     */
    public function compteLancement(Request $request): JsonResponse
    {
        Gate::authorize('surveillances.assign');

        $session = SessionExamen::findOrFail((int) $request->input('session_id'));
        $faculteId = $request->user()->faculteRestreinteId();

        $epreuves = Epreuve::where('session_id', $session->id)
            ->where('statut', '!=', 'cancelled')
            ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
            ->count();

        $enseignants = Teacher::where('actif', true)
            ->when($faculteId !== null, fn ($q) => $q->where(
                fn ($w) => $w->whereHas('departement', fn ($d) => $d->where('faculte_id', $faculteId))
                    ->orWhereNull('departement_id')
            ))
            ->count();

        return response()->json([
            'session_id' => $session->id,
            'session_nom' => $session->nom,
            'epreuves' => $epreuves,
            'enseignants_disponibles' => $enseignants,
        ]);
    }

    /**
     * Consultation de l'état d'un traitement en arrière-plan (polling léger Alpine.js).
     */
    public function runStatus(Request $request, SurveillanceAssignmentRun $run): JsonResponse
    {
        Gate::authorize('epreuves.view');
        $this->verifierRun($request, $run);

        return response()->json($run->progression());
    }

    /**
     * Détection du run actif pour une session donnée.
     */
    public function runActif(Request $request): JsonResponse
    {
        Gate::authorize('epreuves.view');

        $sessionId = $request->filled('session_id') ? (int) $request->input('session_id') : null;
        if (! $sessionId) {
            return response()->json(['run' => null]);
        }

        $faculteId = $request->user()->faculteRestreinteId();
        $run = SurveillanceAssignmentRun::actifPour($sessionId, $faculteId);

        return response()->json([
            'run' => $run?->progression(),
        ]);
    }

    /**
     * Analyse dynamique des conflits réels sur une épreuve (capacité, cours réguliers, indisponibilités, doublets).
     *
     * @param  Collection<int, Collection<int, ScheduleSlot>>|null  $slotsParEnseignant
     * @param  \Illuminate\Database\Eloquent\Collection<int, Epreuve>|null  $autresEpreuvesParEnseignant
     * @return array<int, array{type: string, message: string}>
     */
    private function analyserConflitsEpreuve(
        Epreuve $epreuve,
        $slotsParEnseignant = null,
        $autresEpreuvesParEnseignant = null
    ): array {
        $conflits = [];

        // 1. Capacité
        if ($epreuve->salle !== null && $epreuve->salle->capacite !== null) {
            $nbCandidats = max(
                $epreuve->candidats_count ?? 0,
                (int) ($epreuve->capacite_necessaire ?? 0),
                $epreuve->groupe?->etudiants?->count() ?? 0
            );
            if ($nbCandidats > (int) $epreuve->salle->capacite) {
                $deficit = $nbCandidats - (int) $epreuve->salle->capacite;
                $conflits[] = [
                    'type' => 'capacite',
                    'message' => "Capacité insuffisante : {$nbCandidats} étudiants pour {$epreuve->salle->capacite} places ({$epreuve->salle->code}, déficit de {$deficit} places).",
                ];
            }
        }

        // 2. Conflits d'enseignants affectés
        $surveillantsActifs = $epreuve->surveillants->whereIn('statut', ['assigne', 'confirme']);
        $jourSemaine = strtolower($epreuve->date instanceof CarbonInterface ? $epreuve->date->format('l') : Carbon::parse($epreuve->date)->format('l'));
        $dateStr = $epreuve->date instanceof CarbonInterface ? $epreuve->date->toDateString() : (string) $epreuve->date;

        foreach ($surveillantsActifs as $surv) {
            if ($surv->teacher_id !== null) {
                $t = $surv->enseignant;
                if ($t !== null && ! $t->actif) {
                    $conflits[] = [
                        'type' => 'enseignant_inactif',
                        'message' => "L'enseignant {$t->nom_complet} affecté est inactif.",
                    ];
                }

                // Vérifier créneau régulier (ScheduleSlot) en mémoire ou en fallback
                if ($slotsParEnseignant !== null) {
                    $slots = $slotsParEnseignant->get($surv->teacher_id, collect());
                    $slotConflit = $slots
                        ->where('day_of_week', $jourSemaine)
                        ->first(fn ($slot) => $slot->chevauche((string) $epreuve->heure_debut, (string) $epreuve->heure_fin));
                } else {
                    $slotConflit = ScheduleSlot::where('teacher_id', $surv->teacher_id)
                        ->where('day_of_week', $jourSemaine)
                        ->where('status', '!=', 'cancelled')
                        ->with('matiere')
                        ->get()
                        ->first(fn ($slot) => $slot->chevauche((string) $epreuve->heure_debut, (string) $epreuve->heure_fin));
                }

                if ($slotConflit !== null) {
                    $conflits[] = [
                        'type' => 'cours_regulier',
                        'message' => "{$surv->enseignant?->nom_complet} a déjà un cours régulier (« {$slotConflit->matiere?->nom} ») à ce créneau.",
                    ];
                }

                // Vérifier chevauchement avec une autre épreuve active en mémoire ou en fallback
                if ($autresEpreuvesParEnseignant !== null) {
                    $autreEpreuveConflit = $autresEpreuvesParEnseignant
                        ->where('id', '!=', $epreuve->id)
                        ->first(function ($autre) use ($dateStr, $surv, $epreuve) {
                            $autreDate = $autre->date instanceof CarbonInterface ? $autre->date->toDateString() : (string) $autre->date;
                            if ($autreDate !== $dateStr) {
                                return false;
                            }
                            $estSurveillant = $autre->surveillants->contains(
                                fn ($s) => (int) $s->teacher_id === (int) $surv->teacher_id && in_array($s->statut, ['assigne', 'confirme'], true)
                            );
                            if (! $estSurveillant) {
                                return false;
                            }

                            return (string) $epreuve->heure_debut < (string) $autre->heure_fin && (string) $autre->heure_debut < (string) $epreuve->heure_fin;
                        });
                } else {
                    $autreEpreuveConflit = Epreuve::where('id', '!=', $epreuve->id)
                        ->whereDate('date', $epreuve->date)
                        ->where('statut', '!=', 'cancelled')
                        ->whereHas('surveillants', fn ($s) => $s->where('teacher_id', $surv->teacher_id)->whereIn('statut', ['assigne', 'confirme']))
                        ->with('matiere')
                        ->get()
                        ->first(fn ($autre) => (string) $epreuve->heure_debut < (string) $autre->heure_fin && (string) $autre->heure_debut < (string) $epreuve->heure_fin);
                }

                if ($autreEpreuveConflit !== null) {
                    $conflits[] = [
                        'type' => 'conflit_examen',
                        'message' => "{$surv->enseignant?->nom_complet} est déjà affecté à l'épreuve « {$autreEpreuveConflit->code} » sur ce créneau.",
                    ];
                }
            }
        }

        return $conflits;
    }

    private function verifierFaculte(Epreuve $epreuve): void
    {
        if ($epreuve->faculteId() !== null && request()->user()?->faculteRestreinteId() !== null
            && ! request()->user()->peutAccederFaculte($epreuve->faculteId())) {
            abort(404);
        }
    }

    /**
     * Isolation faculté des traitements : un compte restreint ne consulte
     * que les runs de sa faculté (les runs globaux restent invisibles).
     */
    private function verifierRun(Request $request, SurveillanceAssignmentRun $run): void
    {
        $faculteId = $request->user()->faculteRestreinteId();

        if ($faculteId !== null && (int) $run->faculte_id !== $faculteId) {
            abort(404);
        }
    }
}
