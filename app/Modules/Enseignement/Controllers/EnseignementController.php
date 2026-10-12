<?php

namespace App\Modules\Enseignement\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AnneeUniversitaire;
use App\Models\Matiere;
use App\Modules\Enseignement\Models\AffectationEnseignement;
use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Enseignement\Models\EnseignementGroupe;
use App\Modules\Enseignement\Models\SousGroupe;
use App\Modules\Enseignement\Models\TypeEnseignement;
use App\Modules\Enseignement\Services\AffectationEnseignementService;
use App\Modules\Enseignement\Services\EnseignementService;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Teacher;
use App\Support\Recherche;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Enseignements (§7, §27, §31, §44) : dashboard, table filtrée, fiche,
 * transitions, affectations enseignants/groupes.
 */
class EnseignementController extends Controller
{
    public function __construct(
        private readonly EnseignementService $enseignements,
        private readonly AffectationEnseignementService $affectations,
    ) {}

    public function tableauBord(Request $request): View
    {
        Gate::authorize('viewAny', Enseignement::class);

        $base = $this->scope(Enseignement::query(), $request);

        return view('modules.enseignement.tableau-bord', [
            'compteurs' => [
                'enseignements' => (clone $base)->count(),
                'valides' => (clone $base)->whereIn('statut', ['validated', 'active'])->count(),
                'brouillons' => (clone $base)->whereIn('statut', ['draft', 'planned'])->count(),
                'heures_prevues' => (int) (clone $base)->selectRaw(
                    'COALESCE(SUM(volume_cm + volume_td + volume_tp + volume_projet + volume_autre), 0) as total'
                )->value('total'),
                'affectations' => AffectationEnseignement::where('statut', 'active')->count(),
            ],
            'sansEnseignant' => (clone $base)->whereDoesntHave('affectations',
                fn ($q) => $q->where('statut', 'active'))->limit(8)->get(),
        ]);
    }

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Enseignement::class);

        $query = $this->scope(
            Enseignement::with(['matiere', 'type', 'anneeUniversitaire', 'programme']), $request
        );

        foreach ([
            'matiere_id' => 'matiere_id', 'type_id' => 'type_id',
            'programme_id' => 'programme_id', 'statut' => 'statut',
            'annee_universitaire_id' => 'annee_universitaire_id',
        ] as $input => $colonne) {
            if ($request->input($input) !== null && $request->input($input) !== '') {
                $query->where($colonne, $request->input($input));
            }
        }

        if ($search = trim($request->input('search', ''))) {
            $motif = Recherche::contient($search);
            $query->whereHas('matiere', fn ($w) => $w
                ->where('code', 'like', $motif)->orWhere('nom', 'like', $motif));
        }

        return view('modules.enseignement.index', [
            'enseignements' => $query->orderByDesc('id')->paginate(12)->withQueryString(),
            'matieres' => Matiere::whereNotNull('ue_id')->orderBy('code')->get(),
            'types' => TypeEnseignement::where('actif', true)->orderBy('code')->get(),
            'annees' => AnneeUniversitaire::orderByDesc('date_debut')->get(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Enseignement::class);

        return view('modules.enseignement.form', [
            'enseignement' => null,
            'matieres' => Matiere::whereNotNull('ue_id')->orderBy('code')->get(),
            'types' => TypeEnseignement::where('actif', true)->orderBy('code')->get(),
            'annees' => AnneeUniversitaire::orderByDesc('date_debut')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Enseignement::class);

        $donnees = $request->validate([
            'matiere_id' => 'required|exists:matieres,id',
            'type_id' => 'required|exists:types_enseignement,id',
            'annee_universitaire_id' => 'required|exists:annees_universitaires,id',
            'semestre_id' => 'nullable|exists:semestres_lmd,id',
            'programme_id' => 'nullable|exists:programmes,id',
            'volume_cm' => 'nullable|integer|min:0|max:999',
            'volume_td' => 'nullable|integer|min:0|max:999',
            'volume_tp' => 'nullable|integer|min:0|max:999',
            'volume_projet' => 'nullable|integer|min:0|max:999',
            'volume_autre' => 'nullable|integer|min:0|max:999',
            'description' => 'nullable|string|max:2000',
        ]);

        // Isolation faculté (audit sécurité 04/10/2026) : l'ECUE et le
        // programme ciblés doivent relever du périmètre — scope → 404.
        $matiere = Matiere::findOrFail($donnees['matiere_id']);
        $matiereFaculte = $matiere->faculteId();
        abort_unless($matiereFaculte === null || $request->user()->peutAccederFaculte($matiereFaculte), 404);
        if (isset($donnees['programme_id'])) {
            $programme = Programme::findOrFail($donnees['programme_id']);
            abort_unless($programme->faculte_id === null
                || $request->user()->peutAccederFaculte((int) $programme->faculte_id), 404);
        }

        try {
            $enseignement = $this->enseignements->creer(
                $matiere,
                TypeEnseignement::findOrFail($donnees['type_id']),
                $donnees, (int) $request->user()->id,
            );
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['enseignement' => $e->getMessage()])->withInput();
        } catch (UniqueConstraintViolationException) {
            return redirect()->back()->withErrors([
                'enseignement' => 'Cet enseignement existe déjà (ECUE × type × année).',
            ])->withInput();
        }

        return redirect()->route('admin.enseignements.show', $enseignement)
            ->with('success', 'Enseignement créé en brouillon.');
    }

    public function show(Request $request, Enseignement $enseignement): View
    {
        Gate::authorize('view', $enseignement);

        $enseignement->loadMissing([
            'matiere.ue.programme', 'matiere.composantes', 'matiere.responsable',
            'matiere.prerequis', 'type', 'anneeUniversitaire', 'semestre',
            'programme', 'affectations.teacher', 'groupes',
        ]);

        return view('modules.enseignement.show', [
            'enseignement' => $enseignement,
            'enseignants' => Teacher::where('actif', true)->orderBy('nom')->get(),
            'groupes' => Group::orderBy('code')
                ->when($request->user()->faculteRestreinteId() !== null,
                    fn ($q) => $q->where('faculte_id', $request->user()->faculteRestreinteId()))
                ->get(),
            'sousGroupes' => SousGroupe::with('groupe')->orderBy('code')->get(),
        ]);
    }

    public function edit(Enseignement $enseignement): View
    {
        Gate::authorize('update', $enseignement);

        return view('modules.enseignement.form', [
            'enseignement' => $enseignement->loadMissing('matiere'),
            'matieres' => Matiere::whereNotNull('ue_id')->orderBy('code')->get(),
            'types' => TypeEnseignement::where('actif', true)->orderBy('code')->get(),
            'annees' => AnneeUniversitaire::orderByDesc('date_debut')->get(),
        ]);
    }

    public function update(Request $request, Enseignement $enseignement): RedirectResponse
    {
        Gate::authorize('update', $enseignement);

        $donnees = $request->validate([
            'volume_cm' => 'nullable|integer|min:0|max:999',
            'volume_td' => 'nullable|integer|min:0|max:999',
            'volume_tp' => 'nullable|integer|min:0|max:999',
            'volume_projet' => 'nullable|integer|min:0|max:999',
            'volume_autre' => 'nullable|integer|min:0|max:999',
            'description' => 'nullable|string|max:2000',
            'motif' => 'nullable|string|max:255',
        ]);

        $motif = $donnees['motif'] ?? null;
        unset($donnees['motif']);

        try {
            $this->enseignements->modifier($enseignement, array_filter(
                $donnees, fn ($v) => $v !== null), (int) $request->user()->id, $motif);
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['enseignement' => $e->getMessage()])->withInput();
        }

        return redirect()->route('admin.enseignements.show', $enseignement)
            ->with('success', 'Enseignement modifié.');
    }

    public function destroy(Enseignement $enseignement): RedirectResponse
    {
        Gate::authorize('delete', $enseignement);

        if ($enseignement->estVerrouille()) {
            return redirect()->back()->withErrors([
                'enseignement' => 'Enseignement verrouillé : archivage uniquement.',
            ]);
        }

        $enseignement->delete();

        return redirect()->route('admin.enseignements.index')
            ->with('success', 'Enseignement supprimé.');
    }

    public function transition(Request $request, Enseignement $enseignement): RedirectResponse
    {
        Gate::authorize('view', $enseignement);

        $cible = $request->validate([
            'statut' => 'required|in:'.implode(',', Enseignement::STATUTS),
        ])['statut'];

        if (in_array($cible, ['validated', 'active'], true)) {
            Gate::authorize('valider', $enseignement);
        } else {
            Gate::authorize('update', $enseignement);
        }

        try {
            $this->enseignements->transiter($enseignement, $cible, (int) $request->user()->id);
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['statut' => $e->getMessage()]);
        }

        return redirect()->route('admin.enseignements.show', $enseignement)
            ->with('success', "Enseignement passé à « {$cible} ».");
    }

    public function affecter(Request $request, Enseignement $enseignement): RedirectResponse
    {
        Gate::authorize('update', $enseignement);

        $valide = $request->validate([
            'teacher_id' => 'required|exists:teachers,id',
            'role' => 'required|in:'.implode(',', AffectationEnseignement::ROLES),
            'volume_heures' => 'required|numeric|min:0|max:999',
            'motif' => 'nullable|string|max:255',
        ]);

        try {
            $this->affectations->affecterEnseignant(
                $enseignement, Teacher::findOrFail($valide['teacher_id']),
                $valide['role'], (float) $valide['volume_heures'],
                (int) $request->user()->id, $valide['motif'] ?? null,
            );
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['affectation' => $e->getMessage()])->withInput();
        } catch (UniqueConstraintViolationException) {
            return redirect()->back()->withErrors([
                'affectation' => 'Affectation identique déjà existante (enseignant × rôle).',
            ])->withInput();
        }

        return redirect()->route('admin.enseignements.show', $enseignement)
            ->with('success', 'Enseignant affecté.');
    }

    public function desaffecter(Request $request, AffectationEnseignement $affectation): RedirectResponse
    {
        $affectation->loadMissing('enseignement');
        Gate::authorize('update', $affectation->enseignement);

        $motif = $request->validate(['motif' => 'nullable|string|max:255'])['motif'] ?? null;
        $this->affectations->desaffecter($affectation, (int) $request->user()->id, $motif);

        return redirect()->route('admin.enseignements.show', $affectation->enseignement_id)
            ->with('success', 'Affectation clôturée.');
    }

    public function rattacherGroupe(Request $request, Enseignement $enseignement): RedirectResponse
    {
        Gate::authorize('update', $enseignement);

        $valide = $request->validate([
            'groupe_id' => 'required|exists:groups,id',
            'sous_groupe_id' => 'nullable|exists:sous_groupes,id',
        ]);

        $groupe = Group::findOrFail($valide['groupe_id']);
        abort_unless($request->user()->peutAccederFaculte((int) $groupe->faculte_id), 403);

        try {
            $this->affectations->rattacherGroupe(
                $enseignement, $groupe, $valide['sous_groupe_id'] ?? null, (int) $request->user()->id
            );
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['groupe' => $e->getMessage()]);
        }

        return redirect()->route('admin.enseignements.show', $enseignement)
            ->with('success', "Groupe {$groupe->code} rattaché.");
    }

    public function detacherGroupe(Request $request, EnseignementGroupe $lien): RedirectResponse
    {
        $lien->loadMissing('enseignement');
        Gate::authorize('update', $lien->enseignement);

        $this->affectations->detacherGroupe($lien, (int) $request->user()->id);

        return redirect()->route('admin.enseignements.show', $lien->enseignement_id)
            ->with('success', 'Groupe détaché.');
    }

    /**
     * @return Builder
     */
    private function scope($query, Request $request)
    {
        if ($request->user()->faculteRestreinteId() !== null) {
            $faculteId = $request->user()->faculteRestreinteId();
            $query->whereHas('matiere.ue.programme', fn ($q) => $q->where('faculte_id', $faculteId));
        }

        return $query;
    }
}
