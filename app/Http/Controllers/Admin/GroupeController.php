<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Enseignement\Models\SousGroupe;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Services\Exports\ListeEtudiantsService;
use App\Support\Recherche;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * Référentiel groupes (§7) : rattachés à une faculté — périmètre
 * appliqué serveur pour les admin_faculte (lecture comme écriture).
 */
class GroupeController extends Controller
{
    public function index(Request $request): View
    {
        $query = Group::with(['faculte', 'programme'])
            ->withCount(['schedules'])
            ->withCount(['affectations as affectations_actives' => fn ($q) => $q->where('statut', 'active')]);

        if ($request->user()->faculteRestreinteId() !== null) {
            $query->where('faculte_id', $request->user()->faculteRestreinteId());
        } elseif ($request->filled('faculte_id')) {
            $query->where('faculte_id', (int) $request->input('faculte_id'));
        }

        if ($search = trim($request->input('search', ''))) {
            $motif = Recherche::contient($search);
            $query->where(fn ($w) => $w->where('code', 'like', $motif)->orWhere('nom', 'like', $motif));
        }

        $filtres = $request->validate([
            'programme_id' => ['nullable', 'integer', 'exists:programmes,id'],
            'niveau' => ['nullable', 'in:L1,L2,L3,M1,M2,D1,D2,D3'],
            'annee_universitaire_id' => ['nullable', 'integer', 'exists:annees_universitaires,id'],
            'faculte_id' => ['nullable', 'integer', 'exists:facultes,id'],
            'statut' => ['nullable', 'in:actif,inactif'],
            'occupation' => ['nullable', 'in:vide,partiel,presque_complet,complet,sureffectif,avec_etudiants'],
            'capacite_min' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'effectif_min' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'places_min' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);

        if (! empty($filtres['programme_id'])) {
            $query->where('programme_id', (int) $filtres['programme_id']);
        }

        if (! empty($filtres['niveau'])) {
            $query->where('niveau', $filtres['niveau']);
        }

        $anneeFiltreId = ! empty($filtres['annee_universitaire_id']) ? (int) $filtres['annee_universitaire_id'] : null;

        if ($anneeFiltreId !== null) {
            $query->where(fn ($q) => $q->where('annee_universitaire_id', $anneeFiltreId)->orWhereNull('annee_universitaire_id'));
        }

        if (! empty($filtres['statut'])) {
            $query->where('actif', $filtres['statut'] === 'actif');
        }

        if (! empty($filtres['capacite_min'])) {
            $query->where('capacite', '>=', (int) $filtres['capacite_min']);
        }

        if (! empty($filtres['occupation'])) {
            $occ = $filtres['occupation'];
            if ($occ === 'vide') {
                $query->whereDoesntHave('affectations', fn ($q) => $q->where('statut', 'active')->when($anneeFiltreId !== null, fn ($sub) => $sub->where('annee_universitaire_id', $anneeFiltreId)));
            } elseif ($occ === 'avec_etudiants') {
                $query->whereHas('affectations', fn ($q) => $q->where('statut', 'active')->when($anneeFiltreId !== null, fn ($sub) => $sub->where('annee_universitaire_id', $anneeFiltreId)));
            } elseif ($occ === 'complet') {
                $query->whereNotNull('capacite')->whereHas('affectations', fn ($q) => $q->where('statut', 'active')->when($anneeFiltreId !== null, fn ($sub) => $sub->where('annee_universitaire_id', $anneeFiltreId)), '>=', DB::raw('groups.capacite'));
            } elseif ($occ === 'sureffectif') {
                $query->whereNotNull('capacite')->whereHas('affectations', fn ($q) => $q->where('statut', 'active')->when($anneeFiltreId !== null, fn ($sub) => $sub->where('annee_universitaire_id', $anneeFiltreId)), '>', DB::raw('groups.capacite'));
            } elseif ($occ === 'presque_complet') {
                $query->whereNotNull('capacite')
                    ->whereHas('affectations', fn ($q) => $q->where('statut', 'active')->when($anneeFiltreId !== null, fn ($sub) => $sub->where('annee_universitaire_id', $anneeFiltreId)), '>=', DB::raw('(groups.capacite * 0.8)'))
                    ->whereHas('affectations', fn ($q) => $q->where('statut', 'active')->when($anneeFiltreId !== null, fn ($sub) => $sub->where('annee_universitaire_id', $anneeFiltreId)), '<', DB::raw('groups.capacite'));
            } elseif ($occ === 'partiel') {
                $query->whereHas('affectations', fn ($q) => $q->where('statut', 'active')->when($anneeFiltreId !== null, fn ($sub) => $sub->where('annee_universitaire_id', $anneeFiltreId)))
                    ->where(function ($w) use ($anneeFiltreId) {
                        $w->whereNull('capacite')
                            ->orWhereHas('affectations', fn ($q) => $q->where('statut', 'active')->when($anneeFiltreId !== null, fn ($sub) => $sub->where('annee_universitaire_id', $anneeFiltreId)), '<', DB::raw('(groups.capacite * 0.8)'));
                    });
            }
        }

        if (! empty($filtres['effectif_min'])) {
            $query->whereHas('affectations', fn ($q) => $q->where('statut', 'active')->when($anneeFiltreId !== null, fn ($sub) => $sub->where('annee_universitaire_id', $anneeFiltreId)), '>=', (int) $filtres['effectif_min']);
        }

        if (isset($filtres['places_min']) && $filtres['places_min'] !== null) {
            $query->whereNotNull('capacite')
                ->whereRaw('(groups.capacite - (select count(*) from affectations_groupe where groups.id = affectations_groupe.groupe_id and statut = \'active\''.($anneeFiltreId !== null ? " and annee_universitaire_id = {$anneeFiltreId}" : '').')) >= ?', [(int) $filtres['places_min']]);
        }

        // Cloner la requête avant pagination pour que les statistiques portent sur l'ensemble des résultats
        // et ne soient pas altérées par le LIMIT 12 de la pagination Eloquent.
        $statsQuery = clone $query;
        $groupes = $query->orderBy('code')->paginate(12)->withQueryString();

        // Stats unifiées : source unique affectations actives (jamais le pointeur).
        // Effectif sommé dans la boucle (pas d'agrégat SQL sur groups.* :
        // incompatible avec ONLY_FULL_GROUP_BY sous MySQL).
        $stats = [
            'groupes' => (clone $statsQuery)->count(),
            'effectif' => 0,
            'complets' => 0,
            'vides' => 0,
            'presque_complets' => 0,
            'capacite_totale' => 0,
        ];

        foreach ($statsQuery->cursor() as $groupe) {
            $occupation = $this->occupation($groupe);
            $stats['effectif'] += (int) ($groupe->affectations_actives ?? $groupe->effectifActuel());
            $stats['capacite_totale'] += (int) ($groupe->capacite ?? 0);

            if ($occupation === 'complet' || $occupation === 'sureffectif') {
                $stats['complets']++;
            }

            if ($occupation === 'vide') {
                $stats['vides']++;
            }

            if ($occupation === 'presque_complet') {
                $stats['presque_complets']++;
            }
        }

        $stats['places_disponibles'] = max(0, $stats['capacite_totale'] - $stats['effectif']);

        return view('admin.groupes.index', [
            'groupes' => $groupes,
            'stats' => $stats,
            'programmes' => $this->programmesAccessibles($request),
            'facultes' => $this->facultesAccessibles($request),
            'annees' => AnneeUniversitaire::orderByDesc('date_debut')->get(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.groupes.form', [
            'facultes' => $this->facultesAccessibles($request),
            'programmes' => $this->programmesAccessibles($request),
            'niveaux' => $this->niveauxAccessibles($request),
            'annees' => AnneeUniversitaire::orderByDesc('date_debut')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $donnees = $this->valider($request);

        $this->verifierPerimetre($request, (int) $donnees['faculte_id']);

        Group::create($donnees + ['actif' => true]);

        return redirect()->route('admin.groupes.index')
            ->with('success', 'Groupe créé.');
    }

    public function edit(Request $request, Group $groupe): View
    {
        $this->verifierPerimetre($request, $groupe->faculte_id);

        return view('admin.groupes.form', [
            'groupe' => $groupe,
            'facultes' => $this->facultesAccessibles($request),
            'programmes' => $this->programmesAccessibles($request),
            'niveaux' => $this->niveauxAccessibles($request),
            'annees' => AnneeUniversitaire::orderByDesc('date_debut')->get(),
        ]);
    }

    public function update(Request $request, Group $groupe): RedirectResponse
    {
        $this->verifierPerimetre($request, $groupe->faculte_id);

        $donnees = $this->valider($request, $groupe->id);

        $this->verifierPerimetre($request, (int) $donnees['faculte_id']);

        $groupe->update($donnees);

        return redirect()->route('admin.groupes.index')
            ->with('success', 'Groupe mis à jour.');
    }

    public function destroy(Request $request, Group $groupe): RedirectResponse
    {
        $this->verifierPerimetre($request, $groupe->faculte_id);

        if ($groupe->affectations()->where('statut', 'active')->exists() || $groupe->schedules()->exists()) {
            return redirect()->back()->withErrors([
                'groupe' => 'Impossible : ce groupe a des affectations actives ou des plannings.',
            ]);
        }

        $groupe->delete();

        return redirect()->route('admin.groupes.index')
            ->with('success', 'Groupe supprimé.');
    }

    /**
     * Étudiants d'un groupe (§9) : moteur central, groupe forcé serveur
     * + recherche/tri/pagination/sélection + liens export/PDF.
     */
    public function etudiants(Request $request, Group $groupe): View
    {
        $this->verifierPerimetre($request, $groupe->faculte_id);

        $filtres = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'sous_groupe_id' => ['nullable', 'integer', 'exists:sous_groupes,id'],
            'statut' => ['nullable', 'in:en_attente,valide,rejete'],
            'tri' => ['nullable', 'in:'.implode(',', ListeEtudiantsService::TRIS)],
            'sens' => ['nullable', 'in:asc,desc'],
        ]);
        $filtres['groupe_id'] = $groupe->id;

        $listes = app(ListeEtudiantsService::class);
        $query = $listes->requete($filtres, $request->user());

        $groupe->loadCount(['affectations as affectations_actives' => fn ($q) => $q->where('statut', 'active')]);
        $sousGroupes = SousGroupe::where('groupe_id', $groupe->id)->orderBy('code')->get();

        return view('admin.groupes.etudiants', [
            'groupe' => $groupe,
            'etudiants' => $query->paginate(25)->withQueryString(),
            'total' => (clone $query)->count(),
            'sousGroupes' => $sousGroupes,
            'filtres' => $filtres,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function valider(Request $request, ?int $ignoreId = null): array
    {
        $validateur = Validator::make($request->all(), [
            'code' => 'required|string|max:20|unique:groups,code,'.$ignoreId,
            'nom' => 'required|string|max:100',
            'faculte_id' => 'required|exists:facultes,id',
            'programme_id' => 'nullable|exists:programmes,id',
            'niveau_id' => 'nullable|exists:niveaux,id',
            'annee_universitaire_id' => 'nullable|exists:annees_universitaires,id',
            'niveau' => 'required|in:L1,L2,L3,M1,M2,D1,D2,D3',
            'filiere' => 'nullable|string|max:150',
            'capacite' => 'nullable|integer|min:1|max:10000',
            'actif' => 'sometimes|boolean',
        ]);

        // Cohérence niveau string ↔ niveau_id (les moteurs lisent le
        // string : la FK ne doit jamais le contredire).
        $validateur->after(function ($validateur) use ($request) {
            $niveauId = $request->input('niveau_id');

            if ($niveauId === null || $niveauId === '') {
                return;
            }

            $niveauRef = Niveau::find($niveauId);

            if ($niveauRef === null || $niveauRef->code !== $request->input('niveau')) {
                $validateur->errors()->add(
                    'niveau_id',
                    "Le niveau référentiel ({$niveauRef?->code}) doit correspondre au niveau du groupe ({$request->input('niveau')})."
                );
            }
        });

        return $validateur->validate();
    }

    public function show(Request $request, Group $groupe): View
    {
        $this->verifierPerimetre($request, $groupe->faculte_id);

        $groupe->loadMissing([
            'faculte', 'programme', 'niveauRef', 'anneeUniversitaire',
            'etudiants.faculte', 'schedules',
        ]);
        $groupe->loadCount(['affectations as affectations_actives' => fn ($q) => $q->where('statut', 'active')]);

        $repartition = [
            'hommes' => $groupe->etudiants->where('sexe', 'M')->count(),
            'femmes' => $groupe->etudiants->where('sexe', 'F')->count(),
        ];

        $enseignements = Enseignement::whereHas(
            'groupes', fn ($q) => $q->where('groups.id', $groupe->id)
        )->with(['matiere', 'type'])->get();

        $sousGroupes = SousGroupe::where('groupe_id', $groupe->id)->get();

        $alertes = [];

        if (! $groupe->actif) {
            $alertes[] = 'Groupe inactif avec des étudiants.';
        }

        if ($groupe->affectations_actives === 0) {
            $alertes[] = 'Groupe vide : aucune affectation active.';
        }

        if ($groupe->capacite !== null && $groupe->affectations_actives > $groupe->capacite) {
            $alertes[] = "Sureffectif : {$groupe->affectations_actives}/{$groupe->capacite}.";
        } elseif ($groupe->estComplet()) {
            $alertes[] = "Groupe complet : {$groupe->affectations_actives}/{$groupe->capacite}.";
        }

        if ($groupe->schedules->isEmpty()) {
            $alertes[] = 'Aucun planning rattaché.';
        }

        if ($groupe->programme_id === null) {
            $alertes[] = 'Sans programme rattaché.';
        }

        return view('admin.groupes.show', compact('groupe', 'repartition', 'alertes', 'enseignements', 'sousGroupes'));
    }

    private function verifierPerimetre(Request $request, int $faculteId): void
    {
        abort_unless($request->user()->peutAccederFaculte($faculteId), 404);
    }

    /**
     * Occupation normalisée (source unique : affectations actives,
     * comme `estComplet()` — pas le pointeur `etudiants.groupe_id`).
     * presque_complet = taux >= 80 % et < 100 %.
     */
    private function occupation(Group $groupe): string
    {
        $effectif = (int) ($groupe->affectations_actives ?? $groupe->effectifActuel());

        if ($effectif === 0) {
            return 'vide';
        }

        if ($groupe->capacite === null) {
            return 'partiel';
        }

        if ($effectif > (int) $groupe->capacite) {
            return 'sureffectif';
        }

        if ($effectif >= (int) $groupe->capacite) {
            return 'complet';
        }

        $taux = $effectif / max(1, (int) $groupe->capacite);

        return $taux >= 0.8 ? 'presque_complet' : 'partiel';
    }

    /**
     * @return Collection<int, Faculte>
     */
    private function facultesAccessibles(Request $request)
    {
        $query = Faculte::orderBy('nom');

        if ($request->user()->faculteRestreinteId() !== null) {
            $query->where('id', $request->user()->faculteRestreinteId());
        }

        return $query->get();
    }

    private function programmesAccessibles(Request $request)
    {
        return Programme::orderBy('code')
            ->when($request->user()->faculteRestreinteId() !== null,
                fn ($q) => $q->where('faculte_id', $request->user()->faculteRestreinteId()))
            ->get();
    }

    private function niveauxAccessibles(Request $request)
    {
        return Niveau::with('programme')->orderBy('ordre')
            ->when($request->user()->faculteRestreinteId() !== null,
                fn ($q) => $q->whereHas('programme',
                    fn ($p) => $p->where('faculte_id', $request->user()->faculteRestreinteId())))
            ->get();
    }
}
