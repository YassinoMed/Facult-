<?php

namespace App\Modules\Evaluation\Controllers;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Models\SurveillanceConstraintConfig;
use App\Modules\Evaluation\Models\SurveillanceConstraintRevision;
use App\Modules\Evaluation\Services\ContraintesSurveillance;
use App\Modules\Evaluation\Services\SurveillanceConstraintResolver;
use App\Modules\Evaluation\Services\SurveillanceEquilibrageService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Administration des contraintes d'affectation des surveillances.
 * Les contraintes HARD de sécurité ne sont pas représentées ici :
 * elles ne sont jamais configurables. Chaque écriture est versionnée
 * (révisions append-only, sans PII).
 */
class ContrainteSurveillanceController
{
    /**
     * @return array<string, mixed>
     */
    public static function regles(): array
    {
        return [
            'min_surveillants' => ['required', 'integer', 'min:1', 'max:20'],
            'min_par_salle' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'ratio_etudiants' => ['required', 'integer', 'min:1', 'max:500'],
            'seuil_grande_salle' => ['nullable', 'integer', 'min:10', 'max:2000'],
            'min_surveillants_grande_salle' => ['nullable', 'integer', 'min:1', 'max:20'],
            'nb_gardes' => ['required', 'integer', 'min:0', 'max:10'],
            'max_surveillants' => ['nullable', 'integer', 'min:1', 'max:50'],
            'poids_surveillance_active' => ['required', 'numeric', 'min:0', 'max:100'],
            'poids_garde_reserve' => ['required', 'numeric', 'min:0', 'max:100'],
            'max_jours' => ['required', 'integer', 'min:1', 'max:7'],
            'mode_limite' => ['required', 'in:souple,strict'],
            'actif_charge_totale' => ['sometimes', 'boolean'],
            'poids_charge_totale' => ['required', 'numeric', 'min:0', 'max:1000'],
            'actif_charge_jour' => ['sometimes', 'boolean'],
            'poids_charge_jour' => ['required', 'numeric', 'min:0', 'max:1000'],
            'actif_gardes' => ['sometimes', 'boolean'],
            'poids_gardes' => ['required', 'numeric', 'min:0', 'max:1000'],
            'actif_limite_jours' => ['sometimes', 'boolean'],
            'penalite_depassement_jours' => ['required', 'numeric', 'min:0', 'max:1000'],
            'actif_dispersion' => ['sometimes', 'boolean'],
            'penalite_dispersion' => ['required', 'numeric', 'min:0', 'max:1000'],
            'actif_nouveau_jour' => ['sometimes', 'boolean'],
            'penalite_nouveau_jour' => ['required', 'numeric', 'min:0', 'max:1000'],
            'actif_charge_enseignement' => ['sometimes', 'boolean'],
            'poids_charge_enseignement' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000'],
            'mode_calcul_charge_enseignement' => ['sometimes', 'nullable', 'in:heures_brutes,hetd'],
            'seuil_surcharge_enseignement' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            'max_surveillances_enseignant' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function index(Request $request, SurveillanceConstraintResolver $resolver): View
    {
        Gate::authorize('surveillances.manage');

        $faculteId = $request->user()->faculteRestreinteId();

        $configs = SurveillanceConstraintConfig::with(['session', 'revisions'])
            ->when($faculteId !== null, fn ($q) => $q->where(
                fn ($w) => $w->where('faculte_id', $faculteId)->orWhereNull('faculte_id')
            ))
            ->orderByRaw('session_examen_id IS NULL, faculte_id IS NULL, annee_universitaire_id IS NULL')
            ->orderByDesc('id')
            ->get();

        return view('modules.evaluation.surveillances-contraintes', [
            'configs' => $configs,
            'effective' => $resolver->resoudre($faculteId, null, null),
            'facultes' => $faculteId !== null
                ? Faculte::whereKey($faculteId)->get()
                : Faculte::where('active', true)->orderBy('nom')->get(),
            'annees' => AnneeUniversitaire::orderByDesc('date_debut')->get(),
            'sessions' => SessionExamen::orderByDesc('date_debut')->limit(30)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('surveillances.manage');

        $faculteId = $request->user()->faculteRestreinteId();

        $portee = $request->validate([
            'faculte_id' => ['nullable', 'exists:facultes,id'],
            'annee_universitaire_id' => ['nullable', 'exists:annees_universitaires,id'],
            'session_examen_id' => ['nullable', 'exists:evaluation_sessions,id'],
        ]);

        // Forme hiérarchique stricte : session seule, faculté+année,
        // année seule, ou système (tout null).
        if (! $this->formeValide($portee)) {
            return back()->withErrors(['portee' => 'Portée invalide : session seule, faculté + année, année seule, ou configuration système.'])->withInput();
        }

        // Un restreint ne crée que dans sa faculté (ou rien sans faculté : interdit).
        if ($faculteId !== null && (int) ($portee['faculte_id'] ?? 0) !== $faculteId) {
            abort(403);
        }
        if ($faculteId !== null && empty($portee['faculte_id'])) {
            abort(403);
        }

        $donnees = $request->validate(self::regles());
        $erreurs = $this->incoherences($donnees = $this->normaliserCases($request, $donnees));
        if ($erreurs !== []) {
            return back()->withErrors($erreurs)->withInput();
        }

        $existant = SurveillanceConstraintConfig::where('faculte_id', $portee['faculte_id'] ?? null)
            ->where('annee_universitaire_id', $portee['annee_universitaire_id'] ?? null)
            ->where('session_examen_id', $portee['session_examen_id'] ?? null)
            ->first();

        if ($existant !== null) {
            $this->verifierConfig($request, $existant);
            $existant->update(array_merge($donnees, [
                'version' => $existant->version + 1,
                'updated_by' => $request->user()->id,
            ]));
            $this->tracerRevision($existant->fresh(), (int) $request->user()->id);

            return redirect()->route('admin.evaluations.surveillances.contraintes.index')
                ->with('succes', "Configuration mise à jour (version {$existant->fresh()->version}).");
        }

        $config = SurveillanceConstraintConfig::create(array_merge($donnees, [
            'faculte_id' => $portee['faculte_id'] ?? null,
            'annee_universitaire_id' => $portee['annee_universitaire_id'] ?? null,
            'session_examen_id' => $portee['session_examen_id'] ?? null,
            'version' => 1,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]));
        $this->tracerRevision($config, (int) $request->user()->id);

        return redirect()->route('admin.evaluations.surveillances.contraintes.index')
            ->with('succes', 'Configuration créée (version 1).');
    }

    public function update(Request $request, SurveillanceConstraintConfig $config): RedirectResponse
    {
        Gate::authorize('surveillances.manage');
        $this->verifierConfig($request, $config);

        $donnees = $request->validate(self::regles());
        $erreurs = $this->incoherences($donnees = $this->normaliserCases($request, $donnees));
        if ($erreurs !== []) {
            return back()->withErrors($erreurs)->withInput();
        }

        $config->update(array_merge($donnees, [
            'version' => $config->version + 1,
            'updated_by' => $request->user()->id,
        ]));
        $this->tracerRevision($config->fresh(), (int) $request->user()->id);

        return redirect()->route('admin.evaluations.surveillances.contraintes.index')
            ->with('succes', "Configuration mise à jour (version {$config->fresh()->version}).");
    }

    /**
     * Restaurer les valeurs par défaut : supprime la ligne scopée
     * (retour au défaut système), jamais de mise à jour silencieuse.
     */
    public function reset(Request $request, ?SurveillanceConstraintConfig $config = null): RedirectResponse
    {
        Gate::authorize('surveillances.manage');

        if ($config === null && $request->filled('session_id')) {
            $config = SurveillanceConstraintConfig::where('session_examen_id', $request->input('session_id'))->first();
        } elseif ($config === null && $request->filled('session_examen_id')) {
            $config = SurveillanceConstraintConfig::where('session_examen_id', $request->input('session_examen_id'))->first();
        } elseif ($config === null && ($request->filled('faculte_id') || $request->filled('annee_id') || $request->filled('annee_universitaire_id'))) {
            $config = SurveillanceConstraintConfig::where('faculte_id', $request->input('faculte_id'))
                ->where('annee_universitaire_id', $request->input('annee_id', $request->input('annee_universitaire_id')))
                ->first();
        }

        if ($config === null) {
            return redirect()->route('admin.evaluations.surveillances.contraintes.index')
                ->with('succes', 'Portée restaurée aux valeurs par défaut.');
        }

        $this->verifierConfig($request, $config);

        if ($config->faculte_id === null && $config->annee_universitaire_id === null && $config->session_examen_id === null) {
            return back()->withErrors(['portee' => 'La configuration système ne peut pas être supprimée.']);
        }

        $config->delete();

        return redirect()->route('admin.evaluations.surveillances.contraintes.index')
            ->with('succes', 'Portée restaurée aux valeurs par défaut.');
    }

    /**
     * Simulation avec une configuration non enregistrée (zéro écriture).
     */
    public function simuler(Request $request, SurveillanceEquilibrageService $equilibrage): View|JsonResponse
    {
        Gate::authorize('surveillances.manage');

        $faculteId = $request->user()->faculteRestreinteId();
        $donnees = $request->validate(array_merge(self::regles(), [
            'session_id' => ['required', 'exists:evaluation_sessions,id'],
        ]));
        $erreurs = $this->incoherences($donnees);
        if ($erreurs !== []) {
            if ($request->wantsJson()) {
                return response()->json(['errors' => $erreurs], 422);
            }

            return back()->withErrors($erreurs)->withInput();
        }

        $session = SessionExamen::findOrFail((int) $donnees['session_id']);
        $contraintes = ContraintesSurveillance::depuisParams($donnees);

        try {
            $simulation = $equilibrage->simulerSession($session, [
                'faculte_id' => $faculteId,
                'contraintes' => $contraintes,
            ]);
        } catch (DomainException $e) {
            if ($request->wantsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return back()->withErrors(['simulation' => $e->getMessage()])->withInput();
        }

        if ($request->wantsJson()) {
            return response()->json(array_merge($simulation, [
                'success' => true,
            ]));
        }

        return view('modules.evaluation.surveillances-contraintes-simulation', [
            'simulation' => $simulation,
            'contraintes' => $contraintes,
            'session' => $session,
        ]);
    }

    /**
     * Cases à cocher : absentes = false explicite (sinon impossible de
     * désactiver un objectif à la modification). Les champs du critère
     * charge d'enseignement absents du formulaire prennent leurs
     * défauts moteur (poids 5.0, mode heures_brutes).
     *
     * @param  array<string, mixed>  $donnees
     * @return array<string, mixed>
     */
    private function normaliserCases(Request $request, array $donnees): array
    {
        foreach (['actif_charge_totale', 'actif_charge_jour', 'actif_gardes', 'actif_limite_jours', 'actif_dispersion', 'actif_nouveau_jour', 'actif_charge_enseignement'] as $case) {
            $donnees[$case] = $request->boolean($case);
        }

        if (! isset($donnees['min_par_salle']) || $donnees['min_par_salle'] === null || $donnees['min_par_salle'] === '') {
            $donnees['min_par_salle'] = 1;
        }

        if (! isset($donnees['poids_charge_enseignement']) || $donnees['poids_charge_enseignement'] === null || $donnees['poids_charge_enseignement'] === '') {
            $donnees['poids_charge_enseignement'] = 5.0;
        }
        if (empty($donnees['mode_calcul_charge_enseignement'])) {
            $donnees['mode_calcul_charge_enseignement'] = 'heures_brutes';
        }

        return $donnees;
    }

    /**
     * @param  array<string, mixed>  $portee
     */
    private function formeValide(array $portee): bool
    {
        $session = ! empty($portee['session_examen_id']);
        $faculte = ! empty($portee['faculte_id']);
        $annee = ! empty($portee['annee_universitaire_id']);

        return ($session && ! $faculte && ! $annee)
            || (! $session && $faculte && $annee)
            || (! $session && ! $faculte && $annee)
            || (! $session && ! $faculte && ! $annee);
    }

    /**
     * @param  array<string, mixed>  $donnees
     * @return array<string, string>
     */
    private function incoherences(array $donnees): array
    {
        $erreurs = [];

        if (isset($donnees['max_surveillants'])
            && (int) $donnees['max_surveillants'] < (int) $donnees['min_surveillants']) {
            $erreurs['max_surveillants'] = 'Le maximum doit être supérieur ou égal au minimum.';
        }

        if (($donnees['mode_limite'] ?? 'souple') === 'strict' && empty($donnees['actif_limite_jours'])) {
            $erreurs['mode_limite'] = 'Le mode strict exige l’objectif limite de jours actif.';
        }

        if (isset($donnees['max_surveillants']) && $donnees['max_surveillants'] !== null
            && (int) ($donnees['min_par_salle'] ?? 1) > max((int) $donnees['min_surveillants'], (int) $donnees['max_surveillants'])) {
            $erreurs['min_par_salle'] = 'Le minimum par salle dépasse le plafond applicable : aucune affectation ne pourrait le satisfaire.';
        }

        if (isset($donnees['max_surveillants']) && $donnees['max_surveillants'] !== null
            && isset($donnees['min_surveillants_grande_salle']) && $donnees['min_surveillants_grande_salle'] !== null
            && (int) $donnees['min_surveillants_grande_salle'] > max((int) $donnees['min_surveillants'], (int) $donnees['max_surveillants'])) {
            $erreurs['min_surveillants_grande_salle'] = 'Le minimum pour grande salle dépasse le plafond applicable : aucune affectation ne pourrait le satisfaire.';
        }

        return $erreurs;
    }

    private function verifierConfig(Request $request, SurveillanceConstraintConfig $config): void
    {
        $faculteId = $request->user()->faculteRestreinteId();

        // Restreint : uniquement ses lignes faculté ; système/année/
        // session d'autrui = 404 (jamais d'IDOR silencieux).
        if ($faculteId !== null && (int) $config->faculte_id !== $faculteId) {
            abort(404);
        }
    }

    private function tracerRevision(SurveillanceConstraintConfig $config, int $auteurId): void
    {
        SurveillanceConstraintRevision::create([
            'config_id' => $config->id,
            'version' => $config->version,
            'params' => $config->toArray(),
            'auteur_id' => $auteurId,
            'created_at' => now(),
        ]);
    }
}
