<?php

namespace App\Modules\Referentiel\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\MaquetteUe;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Referentiel\Services\MaquetteComparaisonService;
use App\Modules\Referentiel\Services\MaquetteService;
use App\Modules\Referentiel\Support\EntitesReferentiel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Maquettes versionnées (§18-19) : création, contenu (brouillon),
 * transitions contrôlées, duplication en nouvelle version.
 */
class MaquetteController extends Controller
{
    public function __construct(
        private readonly MaquetteService $maquettes,
        private readonly MaquetteComparaisonService $comparaisons,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('referentiel.view');

        $query = Maquette::with(['programme.faculte', 'anneeUniversitaire'])
            ->when($request->user()->faculteRestreinteId() !== null,
                fn ($q) => $q->whereHas('programme',
                    fn ($p) => $p->where('faculte_id', $request->user()->faculteRestreinteId())));

        if ($request->input('programme_id') !== null && $request->input('programme_id') !== '') {
            $query->where('programme_id', (int) $request->input('programme_id'));
        }

        if ($request->input('statut') !== null && $request->input('statut') !== '') {
            $query->where('statut', $request->input('statut'));
        }

        return view('modules.referentiel.maquettes.index', [
            'maquettes' => $query->orderByDesc('id')->paginate(12)->withQueryString(),
            'programmes' => EntitesReferentiel::options('programmes', $request->user()->faculteRestreinteId()),
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('referentiel.manage');

        return view('modules.referentiel.maquettes.form', [
            'maquette' => null,
            'programmes' => EntitesReferentiel::options('programmes', $request->user()->faculteRestreinteId()),
            'annees' => EntitesReferentiel::options('annees', null),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('referentiel.manage');

        $donnees = $request->validate([
            'programme_id' => 'required|exists:programmes,id',
            'annee_universitaire_id' => 'required|exists:annees_universitaires,id',
            'date_effet' => 'nullable|date',
            'commentaire' => 'nullable|string|max:2000',
        ]);

        $programme = Programme::findOrFail($donnees['programme_id']);
        abort_unless($request->user()->peutAccederFaculte((int) $programme->faculte_id), 403);

        $version = (Maquette::where('programme_id', $programme->id)
            ->where('annee_universitaire_id', $donnees['annee_universitaire_id'])
            ->max('version') ?? 0) + 1;

        $maquette = Maquette::create($donnees + [
            'version' => $version,
            'statut' => 'draft',
            'auteur_id' => $request->user()->id,
        ]);

        return redirect()->route('admin.referentiel.maquettes.show', $maquette)
            ->with('success', "Maquette v{$version} créée en brouillon.");
    }

    public function show(Request $request, Maquette $maquette): View
    {
        $maquette->loadMissing([
            'programme.niveaux.semestres.unitesEnseignement.ecues',
            'programme.faculte',
            'anneeUniversitaire', 'auteur', 'versionPrecedente',
        ]);
        Gate::authorize('view', $maquette);

        // Vague 3 : lignes UE du plan + contextes ECUE (valeurs annuelles).
        // LOOP 03 (éditeur) : prérequis préchargés en 2 requêtes (jamais
        // en boucle), semestres des lignes pour le regroupement.
        $lignesUe = MaquetteUe::where('maquette_id', $maquette->id)
            ->with(['ue.ecues.prerequis', 'ecues.matiere.prerequis', 'semestre.niveau'])
            ->orderBy('id')
            ->get();

        return view('modules.referentiel.maquettes.show', [
            'maquette' => $maquette,
            'lignesUe' => $lignesUe,
            'totaux' => $this->totauxPlan($lignesUe),
            // LOOP C1 : lignage des versions du programme (chaîne
            // version_precedente_id lue à l'envers : versions triées,
            // lecture seule, sans nouvelle table).
            'versions' => Maquette::with('anneeUniversitaire')
                ->where('programme_id', $maquette->programme_id)
                ->orderByDesc('version')
                ->get(),
        ]);
    }

    /**
     * Totaux du plan (LOOP 03) : agrégation PHP sur relations chargées —
     * crédits et volumes par semestre + global, sans requête.
     *
     * @return array{global: array<string, int|float>, semestres: array<int, array<string, mixed>>}
     */
    private function totauxPlan(iterable $lignesUe): array
    {
        $global = ['ues' => 0, 'ecues' => 0, 'credits' => 0, 'cm' => 0, 'td' => 0, 'tp' => 0];
        $semestres = [];

        foreach ($lignesUe as $ligne) {
            $codeSemestre = $ligne->semestre?->code ?? $ligne->ue?->semestre?->code ?? '?';
            $cle = $ligne->semestre?->id ?? 'sans-'.$ligne->id;

            if (! isset($semestres[$cle])) {
                $semestres[$cle] = [
                    'code' => $codeSemestre, 'ues' => 0, 'ecues' => 0,
                    'credits' => 0, 'cm' => 0, 'td' => 0, 'tp' => 0,
                ];
            }

            $semestres[$cle]['ues']++;
            $global['ues']++;

            foreach ($ligne->ecues as $contexte) {
                $semestres[$cle]['ecues']++;
                $semestres[$cle]['credits'] += (int) $contexte->credits;
                $semestres[$cle]['cm'] += (int) $contexte->volume_cm;
                $semestres[$cle]['td'] += (int) $contexte->volume_td;
                $semestres[$cle]['tp'] += (int) $contexte->volume_tp;
                $global['ecues']++;
                $global['credits'] += (int) $contexte->credits;
                $global['cm'] += (int) $contexte->volume_cm;
                $global['td'] += (int) $contexte->volume_td;
                $global['tp'] += (int) $contexte->volume_tp;
            }
        }

        return ['global' => $global, 'semestres' => array_values($semestres)];
    }

    public function destroy(Request $request, Maquette $maquette): RedirectResponse
    {
        Gate::authorize('delete', $maquette);

        if ($maquette->statut !== 'draft') {
            return redirect()->back()->withErrors([
                'maquette' => 'Seul un brouillon est supprimable.',
            ]);
        }

        $maquette->delete();

        return redirect()->route('admin.referentiel.maquettes.index')
            ->with('success', 'Brouillon de maquette supprimé.');
    }

    /**
     * Transition de statut (approved/active exigent maquettes.approve).
     */
    public function transition(Request $request, Maquette $maquette): RedirectResponse
    {
        $maquette->loadMissing('programme');
        Gate::authorize('view', $maquette);

        $cible = $request->validate([
            'statut' => 'required|in:'.implode(',', Maquette::STATUTS),
        ])['statut'];

        if (in_array($cible, ['approved', 'active'], true)) {
            Gate::authorize('approuver', $maquette);
        } else {
            Gate::authorize('update', $maquette);
        }

        try {
            $this->maquettes->transiter($maquette, $cible, (int) $request->user()->id);
        } catch (\DomainException $e) {
            // LOOP 12 : traçabilité des refus (ids + cible, sans PII) —
            // même canal que les transitions acceptées (AuditMaquette).
            Log::warning('referentiel.maquette.transition_refusee', [
                'maquette_id' => $maquette->id,
                'programme_id' => $maquette->programme_id,
                'statut' => $maquette->statut,
                'cible' => $cible,
                'auteur_id' => (int) $request->user()->id,
            ]);

            return redirect()->back()->withErrors(['statut' => $e->getMessage()]);
        }

        return redirect()->route('admin.referentiel.maquettes.show', $maquette)
            ->with('success', "Maquette passée à « {$cible} ».");
    }

    /**
     * Contenu (UE rattachées) — brouillon uniquement, transactionnel.
     */
    public function contenu(Request $request, Maquette $maquette): RedirectResponse
    {
        Gate::authorize('update', $maquette);

        $ues = $request->validate([
            'ues' => 'nullable|array',
            'ues.*.ue_id' => 'required|exists:unites_enseignement,id',
            'ues.*.semestre_id' => 'required|exists:semestres_lmd,id',
        ])['ues'] ?? [];

        // Cohérence : UE du même programme, semestre existant.
        $maquette->loadMissing('programme');
        foreach ($ues as $entree) {
            $ue = UniteEnseignement::findOrFail($entree['ue_id']);
            abort_unless((int) $ue->programme_id === (int) $maquette->programme_id, 422,
                'Toutes les UE doivent appartenir au programme de la maquette.');

            // INV-5 (audit 08/10/2026) : le semestre du pivot doit être
            // celui de l'UE — sinon deux sources de vérité divergentes
            // dans le même plan d'études.
            abort_unless((int) $entree['semestre_id'] === (int) $ue->semestre_id, 422,
                "Le semestre choisi pour l'UE {$ue->code} doit être celui de l'UE ({$ue->semestre?->code}).");
        }

        try {
            $this->maquettes->definirContenu($maquette, array_values($ues), (int) $request->user()->id);
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['maquette' => $e->getMessage()]);
        }

        return redirect()->route('admin.referentiel.maquettes.show', $maquette)
            ->with('success', 'Contenu de la maquette enregistré.');
    }

    /**
     * Contextes ECUE du plan (Vague 3) : valeurs annuelles + règles
     * Master — brouillon uniquement (service), remplacement complet.
     */
    public function ecues(Request $request, Maquette $maquette): RedirectResponse
    {
        Gate::authorize('update', $maquette);

        // Décocher une ligne (matiere_id absent) = retirer l'ECUE du
        // plan : filtrées AVANT validation (remplacement complet, comme
        // definirContenu).
        $brut = collect($request->input('ecues', []))
            ->filter(fn ($entree) => ! empty($entree['matiere_id']) && ! empty($entree['maquette_ue_id']))
            ->values()->all();

        $entrees = validator($brut, [
            '*.maquette_ue_id' => 'required|integer|exists:maquette_ue,id',
            '*.matiere_id' => 'required|integer|exists:matieres,id',
            '*.ordre' => 'nullable|integer|min:0|max:65000',
            '*.coefficient' => 'nullable|numeric|min:0|max:99',
            '*.credits' => 'nullable|integer|min:0|max:60',
            '*.volume_cm' => 'nullable|integer|min:0|max:999',
            '*.volume_td' => 'nullable|integer|min:0|max:999',
            '*.volume_tp' => 'nullable|integer|min:0|max:999',
            '*.type_evaluation' => ['nullable', 'string', 'max:30',
                Rule::exists('evaluation_types_evaluation', 'code')->where('actif', true)],
            '*.regime' => 'nullable|in:obligatoire,optionnelle,conditionnelle',
            '*.groupe_choix' => 'nullable|string|max:30',
            '*.conditions' => 'nullable|array',
        ])->validate();

        // Cohérence : chaque ligne UE appartient à CETTE maquette.
        $lignes = MaquetteUe::where('maquette_id', $maquette->id)->pluck('id');
        foreach ($entrees as $entree) {
            abort_unless($lignes->contains((int) $entree['maquette_ue_id']), 422,
                'Toutes les lignes UE doivent appartenir à la maquette.');
        }

        try {
            $this->maquettes->definirEcues($maquette, array_values($entrees), (int) $request->user()->id);
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['maquette' => $e->getMessage()])->withInput();
        }

        return redirect()->route('admin.referentiel.maquettes.show', $maquette)
            ->with('success', 'Contextes ECUE du plan enregistrés.');
    }

    /**
     * Comparaison de versions (ENTERPRISE LOOP 04) : lecture seule,
     * même programme exigé, périmètre vérifié des deux côtés.
     */
    public function comparer(Request $request, Maquette $maquette): View|RedirectResponse
    {
        $maquette->loadMissing('programme');
        Gate::authorize('view', $maquette);

        $autreId = $request->validate([
            'autre' => 'nullable|integer|exists:maquettes,id',
            'filtre' => 'nullable|in:tous,ajouts,retraits,modifications',
        ])['autre'] ?? null;

        $candidats = Maquette::with('anneeUniversitaire')
            ->where('programme_id', $maquette->programme_id)
            ->where('id', '!=', $maquette->id)
            ->orderByDesc('version')
            ->get();

        $comparaison = null;

        if ($autreId !== null) {
            $autre = Maquette::findOrFail($autreId);
            Gate::authorize('view', $autre);

            try {
                $comparaison = $this->comparaisons->comparer($maquette, $autre);
            } catch (\DomainException $e) {
                return redirect()->back()->withErrors(['autre' => $e->getMessage()]);
            }
        }

        return view('modules.referentiel.maquettes.comparer', [
            'maquette' => $maquette,
            'candidats' => $candidats,
            'autreId' => $autreId,
            'comparaison' => $comparaison,
            'filtre' => $request->input('filtre', 'tous'),
        ]);
    }

    /**
     * Duplication en nouvelle version (snapshot du contenu).
     */
    public function nouvelleVersion(Request $request, Maquette $maquette): RedirectResponse
    {
        Gate::authorize('update', $maquette);

        $donnees = $request->validate([
            'annee_universitaire_id' => 'required|exists:annees_universitaires,id',
            'commentaire' => 'nullable|string|max:2000',
        ]);

        $nouvelle = $this->maquettes->nouvelleVersion(
            $maquette, (int) $donnees['annee_universitaire_id'],
            (int) $request->user()->id, $donnees['commentaire'] ?? null,
        );

        return redirect()->route('admin.referentiel.maquettes.show', $nouvelle)
            ->with('success', "Nouvelle version v{$nouvelle->version} créée en brouillon.");
    }
}
