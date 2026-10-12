<?php

namespace App\Modules\Referentiel\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Referentiel\Models\Domaine;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\Mention;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\Specialite;
use App\Modules\Referentiel\Models\TypeDiplome;
use App\Modules\Referentiel\Services\ChargePedagogiqueService;
use App\Modules\Referentiel\Services\GraphePrerequisService;
use App\Modules\Referentiel\Services\MaquetteActivationSimulationService;
use App\Modules\Referentiel\Services\MatiereStandaloneService;
use App\Modules\Referentiel\Services\ReferentielStatsService;
use App\Modules\Referentiel\Support\EntitesReferentiel;
use App\Support\Recherche;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * CRUD générique du référentiel LMD (§4-13, §23-24) : UN contrôleur
 * piloté par EntitesReferentiel (labels, champs, règles, périmètre).
 * Chaque action : permission + périmètre serveur + validation.
 */
class ReferentielController extends Controller
{
    public function __construct(
        private readonly ReferentielStatsService $stats,
        private readonly MaquetteActivationSimulationService $simulation,
        private readonly MatiereStandaloneService $standalone,
        private readonly ChargePedagogiqueService $charges,
        private readonly GraphePrerequisService $graphe,
    ) {}

    public function tableauBord(Request $request): View
    {
        Gate::authorize('referentiel.view');

        $faculteId = $request->user()->faculteRestreinteId();

        // LOOP 09 : filtre faculté réservé au global — un restreint
        // reste verrouillé sur la sienne (paramètre ignoré, jamais fui).
        $filtreFaculte = null;
        if ($faculteId === null && $request->input('faculte_id') !== null && $request->input('faculte_id') !== '') {
            $filtreFaculte = (int) $request->input('faculte_id');
        }
        $perimetre = $faculteId ?? $filtreFaculte;

        // LOOP 15 : UNE seule comparaison (select + tableau partagent).
        $comparaison = $faculteId === null && $filtreFaculte === null
            ? $this->stats->parFaculte()
            : [];
        $optionsFacultes = $faculteId === null && $filtreFaculte !== null
            ? Faculte::orderBy('nom')->get(['id', 'nom'])
            : [];

        return view('modules.referentiel.tableau-bord', [
            'compteurs' => $this->stats->compteurs($perimetre),
            'anomalies' => $this->stats->anomalies($perimetre, 8),
            // LOOP 15 : la comparaison sert aussi de source au select
            // (jamais de 2e requête facultés) et n'est calculée que si
            // affichée (filtre actif = tableau masqué).
            'facultes' => $comparaison !== [] ? $comparaison : $optionsFacultes,
            'filtreFaculte' => $filtreFaculte,
            // Comparaison multi-facultés : globale uniquement.
            'parFaculte' => $comparaison,
        ]);
    }

    public function arbre(Request $request): View
    {
        Gate::authorize('referentiel.view');

        return view('modules.referentiel.arbre', [
            'facultes' => Faculte::with([
                'departements',
                'domaines.mentions.specialites.programmes.niveaux.semestres.unitesEnseignement.ecues',
                'domaines.mentions.specialites.programmes.maquettes' => fn ($q) => $q->where('statut', 'active'),
            ])
                ->when($request->user()->faculteRestreinteId() !== null,
                    fn ($q) => $q->where('id', $request->user()->faculteRestreinteId()))
                ->orderBy('nom')->get(),
            'recherche' => trim($request->input('q', '')),
        ]);
    }

    public function qualite(Request $request): View
    {
        Gate::authorize('referentiel.view');

        $anomalies = $this->stats->anomalies($request->user()->faculteRestreinteId(), 200);

        if ($request->input('gravite') !== null && $request->input('gravite') !== '') {
            $anomalies = array_values(array_filter(
                $anomalies, fn (array $a) => $a['gravite'] === $request->input('gravite')
            ));
        }

        return view('modules.referentiel.qualite', [
            'anomalies' => array_map($this->enrichirAnomalie(...), $anomalies),
        ]);
    }

    /**
     * Présentation LOOP 06 : catégorie + impact déduits du libellé —
     * le moteur de règles canonique (`ReferentielStatsService`) est
     * inchangé, aucune 2e règle métier ici. Le suivi de traitement
     * est volontairement non persisté : pilotage et prévalidation
     * recalculent l'état à chaque affichage (jamais d'état obsolète),
     * et aucune correction automatique n'existe (choix pédagogique
     * = geste humain, jamais de suppression pour effacer une alerte).
     *
     * @return array<string, string>
     */
    private function enrichirAnomalie(array $anomalie): array
    {
        $probleme = $anomalie['probleme'];
        $entite = $anomalie['entite'];

        [$categorie, $impact] = match (true) {
            str_contains($probleme, 'maquette active') => [
                'Maquettes',
                'Inscriptions pédagogiques et génération d’épreuves bloquées pour ce programme.',
            ],
            str_contains($probleme, 'niveau') => [
                'Programmes',
                'Progression LMD et semestres incomplets pour ce programme.',
            ],
            str_contains($probleme, 'spécialité') => [
                'Programmes',
                'Rattachement académique à compléter (informatif).',
            ],
            str_starts_with($entite, 'UE ') && str_contains($probleme, 'ECUE') => [
                'Unités d’enseignement',
                'UE non enseignable : aucun contenu rattaché.',
            ],
            str_starts_with($entite, 'UE ') => [
                'Crédits',
                'Calculs de crédits et délibérations faussés pour cette UE.',
            ],
            str_contains($probleme, 'crédit') => [
                'Crédits',
                'Totaux de crédits incohérents pour cette ECUE.',
            ],
            default => [
                'Volumes',
                'Charge horaire incomplète pour cette ECUE.',
            ],
        };

        return $anomalie + ['categorie' => $categorie, 'impact' => $impact];
    }

    /**
     * Centre de pilotage des maquettes (ENTERPRISE LOOP 01) : UNE ligne
     * par programme — versions, maquette active, crédits attendus vs
     * observés, UE/ECUE, progression, qualité (même moteur que la
     * prévalidation, calculée sur la page affichée uniquement),
     * disponibilité opérationnelle et actions contextuelles.
     * Un programme actif SANS maquette active n'est JAMAIS présenté
     * comme opérationnel.
     */
    public function pilotage(Request $request): View
    {
        Gate::authorize('referentiel.view');

        $faculteId = $request->user()->faculteRestreinteId();

        $query = Programme::with(['faculte', 'typeDiplome', 'unitesEnseignement'])
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId));

        if ($search = trim($request->input('search', ''))) {
            $motif = Recherche::contient($search);
            $query->where(fn ($w) => $w->where('code', 'like', $motif)
                ->orWhere('nom', 'like', $motif)
                ->orWhere('sigle', 'like', $motif));
        }

        if ($request->input('statut') !== null && $request->input('statut') !== '') {
            $query->where('statut', $request->input('statut'));
        }

        if ($request->input('type_diplome_id') !== null && $request->input('type_diplome_id') !== '') {
            $query->where('type_diplome_id', (int) $request->input('type_diplome_id'));
        }

        $filtreMaquette = $request->input('maquette', '');

        if ($filtreMaquette === 'active') {
            $query->whereHas('maquettes', fn ($q) => $q->where('statut', 'active'));
        } elseif ($filtreMaquette === 'aucune') {
            $query->whereDoesntHave('maquettes');
        } elseif ($filtreMaquette === 'attente') {
            $query->whereHas('maquettes')->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('maquettes as m2')
                    ->whereColumn('m2.programme_id', 'programmes.id')
                    ->where('m2.statut', 'active');
            });
        }

        $programmes = $query->orderBy('code')->paginate(12)->withQueryString();

        // Enrichissement par ligne (page affichée uniquement : 12
        // simulations max, relations partagées avec le moteur).
        $lignes = [];
        foreach ($programmes as $programme) {
            $rapport = $this->simulation->simulerProgramme($programme);

            $ues = $programme->niveaux->flatMap(fn ($n) => $n->semestres->flatMap->unitesEnseignement)
                ->merge($programme->unitesEnseignement)->unique('id')->values();
            $ecues = $ues->flatMap->ecues;
            $semestres = $programme->niveaux->flatMap->semestres;
            $couverts = $semestres->filter(fn ($s) => $s->unitesEnseignement->isNotEmpty())->count();

            $maquetteCourante = $programme->maquettes->sortByDesc('version')->first();

            $lignes[$programme->id] = [
                'rapport' => $rapport,
                'versions' => $programme->maquettes->count(),
                'maquette_courante' => $maquetteCourante,
                'maquette_active' => $programme->maquettes->firstWhere('statut', 'active'),
                'credits_attendus' => $programme->credits_requis,
                'credits_observes' => (int) $ues->sum('credits'),
                'ues' => $ues->count(),
                'ecues' => $ecues->count(),
                'progression' => $semestres->count() > 0
                    ? (int) round($couverts * 100 / $semestres->count()) : 0,
                'semestres_couverts' => $couverts,
                'semestres_total' => $semestres->count(),
                // Disponibilité opérationnelle : seul le plan actif fait
                // foi pour inscrireEcue / genererEpreuves.
                'operationnel' => $rapport['recommandation'] === 'ACTIVE_DEJA',
            ];
        }

        return view('modules.referentiel.pilotage', [
            'programmes' => $programmes,
            'lignes' => $lignes,
            'typesDiplomes' => TypeDiplome::orderBy('ordre')->get(),
        ]);
    }

    /**
     * Prévalidation globale (ENTERPRISE LOOP 02) : rapport du moteur
     * de simulation, lecture seule — l'activation reste un geste
     * manuel sur la fiche maquette après décision explicite.
     */
    public function prevalidation(Request $request): View
    {
        Gate::authorize('referentiel.view');

        return view('modules.referentiel.prevalidation-index', [
            'rapport' => $this->simulation->simuler($request->user()->faculteRestreinteId()),
        ]);
    }

    /**
     * Prévalidation d'un programme : BLOCKER / WARNING / INFO avec
     * règle, entité, correction et consommateurs — sans écriture.
     */
    public function prevalidationProgramme(Request $request, Programme $programme): View
    {
        Gate::authorize('view', $programme);

        return view('modules.referentiel.prevalidation-show', [
            'programme' => $programme,
            'rapport' => $this->simulation->simulerProgramme($programme),
            // B1 : contexte de création guidée (programme + année active
            // présélectionnés — aucun contenu pédagogique pré-rempli).
            'anneeActive' => AnneeUniversitaire::where('active', true)->first(),
        ]);
    }

    /**
     * Inventaire standalone (ENTERPRISE LOOP 07, temps 1) : lecture
     * seule — les temps 3 (validation) et 4 (application) restent en
     * attente de décision humaine, état affiché par ligne.
     */
    public function standalone(Request $request): View
    {
        Gate::authorize('referentiel.view');

        return view('modules.referentiel.standalone', [
            'inventaire' => $this->standalone->inventaire(
                $request->input('search'), $request->input('categorie')
            ),
        ]);
    }

    /**
     * Graphe des prérequis (ENTERPRISE LOOP 11) : programme scopé exigé
     * (données bornées + périmètre), exploration par UE, validation
     * serveur unique via `PrerequisService`.
     */
    public function prerequis(Request $request): View
    {
        Gate::authorize('referentiel.view');

        $faculteId = $request->user()->faculteRestreinteId();
        $programmes = Programme::orderBy('code')
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->get();

        $programme = $programmes->firstWhere('id', (int) $request->input('programme_id'));

        if ($request->input('programme_id') !== null && $programme === null) {
            abort(404);
        }

        if ($programme !== null) {
            Gate::authorize('view', $programme);
        }

        return view('modules.referentiel.prerequis', [
            'programmes' => $programmes,
            'programme' => $programme,
            'graphe' => $programme !== null ? $this->graphe->construire($programme) : null,
        ]);
    }

    /**
     * Charge pédagogique descriptive (ENTERPRISE LOOP 08) : indicateurs
     * calculés sur le canonique, sans seuil inventé.
     */
    public function charges(Request $request): View
    {
        Gate::authorize('referentiel.view');

        $faculteId = $request->user()->faculteRestreinteId();
        $programmes = Programme::orderBy('code')
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->get();

        $programme = $programmes->firstWhere('id', (int) $request->input('programme_id')) ?? $programmes->first();

        // ID navigateur hors périmètre : 404 explicite, jamais de repli
        // silencieux vers un autre programme.
        if ($request->input('programme_id') !== null && $programme !== null
            && (int) $programme->id !== (int) $request->input('programme_id')) {
            abort(404);
        }

        if ($programme !== null) {
            Gate::authorize('view', $programme);
        }

        return view('modules.referentiel.charges', [
            'programmes' => $programmes,
            'programme' => $programme,
            'analyse' => $programme !== null ? $this->charges->analyser($programme) : null,
        ]);
    }

    public function index(Request $request, string $entite): View
    {
        $def = EntitesReferentiel::pour($entite);
        Gate::authorize('viewAny', $def['model']);

        $query = ($def['model'])::query()->with($def['avec']);

        $query = ($def['perimetre'])($query, $request->user()->faculteRestreinteId());

        if ($search = trim($request->input('search', ''))) {
            $motif = Recherche::contient($search);
            $colonnes = $def['recherche'];
            $query->where(function ($w) use ($colonnes, $motif) {
                foreach ($colonnes as $i => $colonne) {
                    $i === 0
                        ? $w->where($colonne, 'like', $motif)
                        : $w->orWhere($colonne, 'like', $motif);
                }
            });
        }

        if ($request->input('actif') !== null && $request->input('actif') !== '') {
            $query->where('actif', (bool) $request->input('actif'));
        }

        return view('modules.referentiel.index', [
            'entite' => $entite,
            'def' => $def,
            'items' => $query->orderBy('code')->paginate(12)->withQueryString(),
        ]);
    }

    public function create(Request $request, string $entite): View
    {
        $def = EntitesReferentiel::pour($entite);
        Gate::authorize('create', $def['model']);

        return view('modules.referentiel.form', [
            'entite' => $entite, 'def' => $def, 'modele' => null,
            'options' => $this->options($request),
        ]);
    }

    public function store(Request $request, string $entite): RedirectResponse
    {
        $def = EntitesReferentiel::pour($entite);
        Gate::authorize('create', $def['model']);

        $donnees = $request->validate(($def['regles'])(null));
        $this->verifierPerimetreCreation($request, $entite, $donnees);

        $donnees['actif'] = (bool) ($donnees['actif'] ?? true);

        if ($entite === 'programmes' && ($erreur = $this->incoherenceProgramme($donnees)) !== null) {
            return redirect()->back()->withErrors($erreur)->withInput();
        }

        if ($entite === 'ues') {
            $donnees['programme_id'] = SemestreLmd::findOrFail($donnees['semestre_id'])
                ->niveau->programme_id;
        }

        ($def['model'])::create($donnees);

        return redirect()->route('admin.referentiel.entites.index', $entite)
            ->with('success', $def['label'].' créé(e).');
    }

    public function show(Request $request, string $entite, int $id): View
    {
        $def = EntitesReferentiel::pour($entite);
        $modele = ($def['model'])::with($def['avec'])->findOrFail($id);
        Gate::authorize('view', $modele);

        if ($entite === 'programmes') {
            $modele->loadMissing([
                'niveaux.semestres.unitesEnseignement.ecues',
                'maquettes.anneeUniversitaire', 'departement', 'specialite.mention.domaine',
            ]);

            return view('modules.referentiel.programmes.show', [
                'programme' => $modele,
            ]);
        }

        foreach ($def['enfants'] as [$relation]) {
            $modele->loadCount($relation);
        }

        return view('modules.referentiel.show', [
            'entite' => $entite, 'def' => $def, 'modele' => $modele,
        ]);
    }

    public function edit(Request $request, string $entite, int $id): View
    {
        $def = EntitesReferentiel::pour($entite);
        $modele = ($def['model'])::findOrFail($id);
        Gate::authorize('update', $modele);

        return view('modules.referentiel.form', [
            'entite' => $entite, 'def' => $def, 'modele' => $modele,
            'options' => $this->options($request),
        ]);
    }

    public function update(Request $request, string $entite, int $id): RedirectResponse
    {
        $def = EntitesReferentiel::pour($entite);
        $modele = ($def['model'])::findOrFail($id);
        Gate::authorize('update', $modele);

        // INV-1/INV-6 (audit 08/10/2026) : périmètre AVANT validation —
        // un restreint ne peut pas cibler le semestre d'une autre
        // faculté (403 explicite, miroir de verifierPerimetreCreation).
        if ($entite === 'ues' && ($idSemestre = $request->input('semestre_id')) !== null) {
            $programmeCible = SemestreLmd::with('niveau.programme')
                ->find((int) $idSemestre)?->niveau?->programme;

            if ($programmeCible !== null) {
                abort_unless($request->user()->peutAccederFaculte((int) $programmeCible->faculte_id), 403);
            }
        }

        $donnees = $request->validate(($def['regles'])($id));

        if (array_key_exists('faculte_id', $donnees)) {
            abort_unless($request->user()->peutAccederFaculte((int) $donnees['faculte_id']), 403);
        }

        $donnees['actif'] = (bool) ($donnees['actif'] ?? false);

        // Programme : spécialité et département de la même faculté
        // (invariant §9 : pas de chaîne FST + FSEGS).
        if ($entite === 'programmes' && ($erreur = $this->incoherenceProgramme($donnees)) !== null) {
            return redirect()->back()->withErrors($erreur)->withInput();
        }

        // UE verrouillée si rattachée à une maquette non-brouillon.
        if ($entite === 'ues' && $this->ueVerrouillee($modele)) {
            return redirect()->back()->withErrors([
                'ue' => 'UE utilisée par une maquette validée : créez une nouvelle version de maquette.',
            ]);
        }

        // Programme archivé : statut terminal, sortie interdite (le
        // bannissement des propositions se fait via `actif` synchronisé).
        if ($entite === 'programmes' && $modele->statut === Programme::STATUT_ARCHIVE
            && ($donnees['statut'] ?? Programme::STATUT_ARCHIVE) !== Programme::STATUT_ARCHIVE) {
            return redirect()->back()->withErrors([
                'statut' => 'Programme archivé (terminal) : créez un nouveau programme plutôt que de le rouvrir.',
            ])->withInput();
        }

        $modele->update($donnees);

        return redirect()->route('admin.referentiel.entites.show', [$entite, $modele->id])
            ->with('success', $def['label'].' mis(e) à jour.');
    }

    public function destroy(Request $request, string $entite, int $id): RedirectResponse
    {
        $def = EntitesReferentiel::pour($entite);
        $modele = ($def['model'])::findOrFail($id);
        Gate::authorize('delete', $modele);

        if ($message = ($def['garde'])($modele)) {
            return redirect()->back()->withErrors(['suppression' => 'Impossible : '.$message]);
        }

        // INV-7 (audit 08/10/2026) : rempart — une FK restrict oubliée
        // d'une garde métier ne doit JAMAIS remonter en 500.
        try {
            $modele->delete();
        } catch (QueryException) {
            return redirect()->back()->withErrors([
                'suppression' => 'Impossible : cette entité est référencée par d\'autres données.',
            ]);
        }

        return redirect()->route('admin.referentiel.entites.index', $entite)
            ->with('success', $def['label'].' supprimé(e).');
    }

    /**
     * @return array<string, array<int, array{id: int, label: string}>>
     */
    private function options(Request $request): array
    {
        $faculteId = $request->user()->faculteRestreinteId();
        $noms = ['facultes', 'domaines', 'mentions', 'specialites', 'departements',
            'types-diplomes', 'programmes', 'niveaux', 'semestres', 'types-ue', 'modes', 'annees',
            'statuts-programme', 'cycles-diplome'];
        $options = [];

        foreach ($noms as $nom) {
            $options[$nom] = EntitesReferentiel::options($nom, $faculteId);
        }

        return $options;
    }

    /**
     * Périmètre à la création : la faculté du parent doit être accessible
     * (les IDs navigateur ne font jamais foi).
     */
    private function verifierPerimetreCreation(Request $request, string $entite, array $donnees): void
    {
        $faculteId = match ($entite) {
            'departements', 'domaines', 'programmes' => $donnees['faculte_id'] ?? null,
            'mentions' => Domaine::find($donnees['domaine_id'] ?? null)?->faculte_id,
            'specialites' => Mention::with('domaine')->find($donnees['mention_id'] ?? null)?->domaine?->faculte_id,
            'niveaux' => Programme::find($donnees['programme_id'] ?? null)?->faculte_id,
            'semestres' => SemestreLmd::with('niveau.programme')->find($donnees['niveau_id'] ?? null)?->niveau?->programme?->faculte_id,
            'ues' => SemestreLmd::with('niveau.programme')->find($donnees['semestre_id'] ?? null)?->niveau?->programme?->faculte_id,
            default => null,
        };

        if ($faculteId !== null) {
            abort_unless($request->user()->peutAccederFaculte((int) $faculteId), 403);
        }
    }

    /**
     * Cohérence faculté d'un programme : spécialité et département doivent
     * appartenir à la même faculté (invariant §9). Retourne [champ => message]
     * ou null si cohérent.
     *
     * @param  array<string, mixed>  $donnees
     * @return array<string, string>|null
     */
    private function incoherenceProgramme(array $donnees): ?array
    {
        $faculteId = (int) ($donnees['faculte_id'] ?? 0);

        if (! empty($donnees['specialite_id'])) {
            $specialite = Specialite::with('mention.domaine')->find($donnees['specialite_id']);
            if ($specialite === null || (int) $specialite->mention->domaine->faculte_id !== $faculteId) {
                return ['specialite_id' => 'La spécialité doit appartenir à la même faculté que le programme.'];
            }
        }

        if (! empty($donnees['departement_id'])) {
            $departement = Departement::find($donnees['departement_id']);
            if ($departement === null || (int) $departement->faculte_id !== $faculteId) {
                return ['departement_id' => 'Le département doit appartenir à la même faculté que le programme.'];
            }
        }

        return null;
    }

    private function ueVerrouillee(object $ue): bool
    {
        return Maquette::whereHas('unitesEnseignement',
            fn ($q) => $q->where('unites_enseignement.id', $ue->id))
            ->whereNotIn('statut', ['draft'])
            ->exists();
    }
}
