<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AnneeUniversitaire;
use App\Models\AssignmentItem;
use App\Models\AssignmentSimulation;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\Specialite;
use App\Modules\Schedule\Models\Group;
use App\Services\Affectations\AssignmentConfirmationService;
use App\Services\Affectations\AssignmentConstraintEngine;
use App\Services\Affectations\AssignmentSimulationService;
use App\Services\DataExchange\ValueNormalizer;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Refonte affectation (UX §35) : paramètres → simulation sans écriture
 * → résumé/équilibrage/exceptions → confirmation par groupe →
 * transaction → audit. Permissions existantes réutilisées
 * (archives.view / archives.update), scope faculté serveur (404).
 */
class AutomaticAssignmentController extends Controller
{
    public function __construct(
        private readonly AssignmentSimulationService $simulations,
        private readonly AssignmentConfirmationService $confirmations,
    ) {}

    public function parametres(Request $request): View
    {
        Gate::authorize('viewAny', Etudiant::class);

        $faculteId = $request->user()->faculteRestreinteId();

        $programmes = Programme::where('actif', true)
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->orderBy('code')
            ->get();

        $specialites = Specialite::where('actif', true)
            ->orderBy('nom')
            ->get();

        $niveaux = Niveau::where('actif', true)
            ->orderBy('ordre')
            ->get();

        return view('admin.affectations.automatique-parametres', [
            'groupes' => $this->groupesScopés($request),
            'annees' => AnneeUniversitaire::orderByDesc('libelle')->get(),
            'anneeDefaut' => AnneeUniversitaire::withCount(['inscriptions as inscriptions_actives' => fn ($q) => $q->whereIn('statut', ['en_cours', 'valide'])])
                ->orderByDesc('inscriptions_actives')->orderByDesc('libelle')->first()?->id,
            'nonAffectesIds' => implode("\n", $this->nonAffectes($request)),
            'programmes' => $programmes,
            'specialites' => $specialites,
            'niveaux' => $niveaux,
            'regimes' => [
                'initiale' => 'Formation initiale',
                'continue' => 'Formation continue',
                'alternance' => 'Alternance',
            ],
            'strategies' => [
                'equilibre' => 'Équilibrage quantitatif (Défaut : contraintes dures puis effectif minimal)',
                'alphabetique' => 'Répartition alphabétique (Distribution par nom/prénom)',
                'mixite_genre' => 'Mixité de genre (Parité Femmes / Hommes équilibrée par groupe)',
                'redoublants_homogenes' => 'Équilibre pédagogique (Distribution homogène des redoublants)',
            ],
        ]);
    }

    public function simuler(Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Etudiant::class);

        $valide = $request->validate([
            'annee_universitaire_id' => ['required', 'exists:annees_universitaires,id'],
            'groupe_ids' => ['required', 'array', 'min:1', 'max:500'],
            'groupe_ids.*' => ['integer', 'exists:groups,id'],
            'programme_id' => ['nullable', 'integer', 'exists:programmes,id'],
            'specialite_id' => ['nullable', 'integer', 'exists:specialites,id'],
            'niveau' => ['nullable', 'string', 'max:10'],
            'regime' => ['nullable', 'string', 'in:initiale,continue,alternance'],
            'strategie' => ['nullable', 'string', 'in:equilibre,alphabetique,mixite_genre,redoublants_homogenes'],
            'etudiant_ids_text' => ['nullable', 'string', 'max:60000'],
        ]);

        // IDs collés (un par ligne) prioritaires, re-scopés faculté ;
        // sinon candidats issus des filtres (dont non-affectés pré-remplis).
        $colles = $this->extraireIds((string) ($valide['etudiant_ids_text'] ?? ''));
        $etudiants = $colles !== []
            ? $this->rescoper($request, $colles)
            : $this->candidats($request, (int) $valide['annee_universitaire_id'], $valide);

        abort_if($etudiants === [], 422, 'Aucun étudiant candidat pour ces paramètres.');

        $simulation = $this->simulations->simuler([
            'annee_id' => (int) $valide['annee_universitaire_id'],
            'groupe_ids' => array_map('intval', $valide['groupe_ids']),
            'etudiant_ids' => $etudiants,
            'programme_id' => $valide['programme_id'] ?? null,
            'specialite_id' => $valide['specialite_id'] ?? null,
            'niveau' => $valide['niveau'] ?? null,
            'regime' => $valide['regime'] ?? null,
            'strategie' => $valide['strategie'] ?? 'equilibre',
        ], (int) $request->user()->id, $request->user()->faculteRestreinteId());

        return redirect()->route('admin.affectations.automatique.show', $simulation);
    }

    public function show(Request $request, AssignmentSimulation $simulation): View
    {
        Gate::authorize('viewAny', Etudiant::class);
        $this->verifierScope($request, $simulation);

        $items = AssignmentItem::where('simulation_id', $simulation->id)
            ->with(['etudiant' => fn ($q) => $q->select('id', 'nom', 'prenom', 'num_inscription', 'faculte_id')])
            ->when($request->filled('groupe'), fn ($q) => $q->where('groupe_propose_id', $request->integer('groupe')))
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->string('statut')))
            ->when($request->string('flex')->toString() === '1', fn ($q) => $q->where('flexibilite', 1))
            ->when($request->string('flex')->toString() === '2-3', fn ($q) => $q->whereBetween('flexibilite', [2, 3]))
            ->when($request->string('flex')->toString() === '4+', fn ($q) => $q->where('flexibilite', '>=', 4))
            ->when($request->string('flex')->toString() === '0', fn ($q) => $q->where('flexibilite', 0))
            ->when($request->filled('q'), function ($q) use ($request) {
                $motif = '%'.str_replace(['%', '_'], '', (string) $request->string('q')).'%';
                $q->whereHas('etudiant', fn ($e) => $e->where('nom', 'like', $motif)->orWhere('prenom', 'like', $motif)->orWhere('num_inscription', 'like', $motif));
            })
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        $parGroupe = AssignmentItem::where('simulation_id', $simulation->id)
            ->whereNotNull('groupe_propose_id')
            ->selectRaw('groupe_propose_id, COUNT(*) total')
            ->groupBy('groupe_propose_id')
            ->pluck('total', 'groupe_propose_id');

        $groupesSimulation = Group::whereIn('id', $simulation->parametres['groupe_ids'] ?? [])->orderBy('code')->get();
        $nonAffectes = AssignmentItem::where('simulation_id', $simulation->id)->where('statut', 'non_affecte')->with('etudiant')->get();
        $moteurPistes = app(AssignmentConstraintEngine::class);
        $inscritsAnnee = InscriptionAcademique::where('annee_universitaire_id', $simulation->annee_universitaire_id)
            ->whereIn('etudiant_id', $nonAffectes->map(fn ($i) => $i->etudiant_id)->all())
            ->pluck('etudiant_id')->all();
        $pistes = [];

        foreach ($nonAffectes as $item) {
            if ($item->etudiant === null) {
                continue;
            }

            $pistes[$item->etudiant_id] = [
                'sans_inscription' => ! in_array($item->etudiant_id, $inscritsAnnee, true),
                'compatibles' => $moteurPistes->groupesCompatibles($item->etudiant, $groupesSimulation),
            ];
        }

        return view('admin.affectations.automatique-show', [
            'simulation' => $simulation,
            'items' => $items,
            'parGroupe' => $parGroupe,
            'groupes' => $groupesSimulation->keyBy('id'),
            'nonAffectes' => $nonAffectes,
            'pistes' => $pistes,
            'annee' => AnneeUniversitaire::find($simulation->annee_universitaire_id),
        ]);
    }

    /**
     * Régénération (§8) : mêmes paramètres, nouveau seed, lignée tracée.
     */
    public function regenerer(Request $request, AssignmentSimulation $simulation): RedirectResponse
    {
        Gate::authorize('viewAny', Etudiant::class);
        $this->verifierScope($request, $simulation);

        $nouvelle = $this->simulations->regenerer($simulation, (int) $request->user()->id, $request->user()->faculteRestreinteId());

        return redirect()->route('admin.affectations.automatique.show', $nouvelle)
            ->with('success', "Nouvelle répartition générée (seed {$nouvelle->seed}). Comparez puis confirmez.");
    }

    public function confirmerGroupe(Request $request, AssignmentSimulation $simulation, int $groupe): RedirectResponse
    {
        Gate::authorize('viewAny', Etudiant::class);
        abort_unless($request->user()->hasPermission('archives.update'), 403);
        $this->verifierScope($request, $simulation);

        try {
            $resultat = $this->confirmations->confirmerGroupe($simulation, $groupe, (int) $request->user()->id);
        } catch (DomainException $e) {
            return back()->withErrors(['confirmation' => $e->getMessage()]);
        }

        // Tranche traitée : s'il reste des items, proposer la suite
        // (pas de worker requis, chaque tranche < timeout HTTP).
        if (! $resultat['termine']) {
            return back()
                ->with('continuer_confirmation', $groupe)
                ->with('success', "Tranche confirmée : {$resultat['confirmees']} affectation(s). Reste {$resultat['restantes']} à confirmer pour ce groupe.");
        }

        return back()->with('success', "Groupe confirmé : {$resultat['confirmees']} affectation(s), {$resultat['ignorees']} ignorée(s).");
    }

    public function modifier(Request $request, AssignmentItem $item): RedirectResponse
    {
        Gate::authorize('viewAny', Etudiant::class);
        abort_unless($request->user()->hasPermission('archives.update'), 403);

        // Isolation faculté (audit sécurité 04/10/2026) : l'item relève
        // de la simulation — mêmes garde-fous que show/confirmer.
        $this->verifierScope($request, $item->simulation);

        $valide = $request->validate(['groupe_id' => ['required', 'exists:groups,id']]);

        try {
            $this->confirmations->modifier($item, (int) $valide['groupe_id'], (int) $request->user()->id);
        } catch (DomainException $e) {
            return back()->withErrors(['modification' => $e->getMessage()]);
        }

        return back()->with('success', 'Proposition modifiée (règles métier vérifiées).');
    }

    public function annuler(Request $request, AssignmentSimulation $simulation): RedirectResponse
    {
        Gate::authorize('viewAny', Etudiant::class);
        abort_unless($request->user()->hasPermission('archives.update'), 403);
        $this->verifierScope($request, $simulation);

        try {
            $this->confirmations->annuler($simulation, (int) $request->user()->id);
        } catch (DomainException $e) {
            return back()->withErrors(['annulation' => $e->getMessage()]);
        }

        return redirect()->route('admin.affectations.automatique.parametres')->with('success', 'Simulation annulée.');
    }

    public function exporter(Request $request, AssignmentSimulation $simulation): StreamedResponse
    {
        Gate::authorize('viewAny', Etudiant::class);
        $this->verifierScope($request, $simulation);

        abort_unless($request->user()->hasPermission('data.export'), 403);

        return response()->streamDownload(function () use ($simulation) {
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF");
            fputcsv($sortie, ['matricule', 'nom', 'prenom', 'groupe_propose', 'statut', 'raison'], ';');

            AssignmentItem::where('simulation_id', $simulation->id)->with(['etudiant', 'groupePropose'])->chunkById(500, function ($items) use ($sortie) {
                foreach ($items as $item) {
                    fputcsv($sortie, [
                        ValueNormalizer::celluleExport((string) ($item->etudiant?->num_inscription ?? '')),
                        $item->etudiant?->nom, $item->etudiant?->prenom,
                        $item->groupePropose?->code ?? '',
                        $item->statut,
                        mb_substr((string) $item->raison, 0, 200),
                    ], ';');
                }
            });

            fclose($sortie);
        }, "simulation-{$simulation->reference}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<int> */
    private function candidats(Request $request, int $anneeId, array $filtres): array
    {
        return Etudiant::where('statut', 'valide')
            ->whereHas('inscriptionsAcademiques', function ($q) use ($anneeId, $filtres) {
                $q->where('annee_universitaire_id', $anneeId)
                    ->whereIn('statut', ['en_cours', 'valide'])
                    ->when(! empty($filtres['regime']), fn ($sub) => $sub->where('regime', $filtres['regime']));
            })
            ->when($request->user()->faculteRestreinteId() !== null, fn ($q) => $q->where('faculte_id', $request->user()->faculteRestreinteId()))
            ->when(! empty($filtres['programme_id']), function ($q) use ($filtres, $anneeId) {
                $q->where(function ($sub) use ($filtres, $anneeId) {
                    $sub->where('programme_id', $filtres['programme_id'])
                        ->orWhereHas('inscriptionsAcademiques', fn ($ins) => $ins->where('annee_universitaire_id', $anneeId)->where('programme_id', $filtres['programme_id']));
                });
            })
            ->when(! empty($filtres['specialite_id']), function ($q) use ($filtres, $anneeId) {
                $q->where(function ($sub) use ($filtres, $anneeId) {
                    $sub->whereHas('programmeRef', fn ($p) => $p->where('specialite_id', $filtres['specialite_id']))
                        ->orWhereHas('inscriptionsAcademiques', fn ($ins) => $ins->where('annee_universitaire_id', $anneeId)->whereHas('programmeRef', fn ($p) => $p->where('specialite_id', $filtres['specialite_id'])));
                });
            })
            ->when(! empty($filtres['niveau']), function ($q) use ($filtres, $anneeId) {
                $q->where(function ($sub) use ($filtres, $anneeId) {
                    $sub->where('niveau', $filtres['niveau'])
                        ->orWhereHas('niveauRef', fn ($n) => $n->where('code', $filtres['niveau']))
                        ->orWhereHas('inscriptionsAcademiques', fn ($ins) => $ins->where('annee_universitaire_id', $anneeId)->where('niveau', $filtres['niveau']));
                });
            })
            ->limit(10000)
            ->pluck('id')
            ->all();
    }

    /** @return array<int> IDs des étudiants sans affectation active (max 5000, scopés). */
    private function nonAffectes(Request $request): array
    {
        return Etudiant::where('statut', 'valide')
            ->whereDoesntHave('affectations', fn ($q) => $q->where('statut', 'active'))
            ->when($request->user()->faculteRestreinteId() !== null, fn ($q) => $q->where('faculte_id', $request->user()->faculteRestreinteId()))
            ->orderBy('id')
            ->limit(5000)
            ->pluck('id')
            ->all();
    }

    /** @return array<int> */
    private function extraireIds(string $texte): array
    {
        preg_match_all('/\d+/', $texte, $correspondances);

        return array_values(array_unique(array_map('intval', array_slice($correspondances[0] ?? [], 0, 5000))));
    }

    /**
     * @param  array<int>  $ids
     * @return array<int> IDs existants, re-scopés faculté.
     */
    private function rescoper(Request $request, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Etudiant::whereIn('id', $ids)
            ->when($request->user()->faculteRestreinteId() !== null, fn ($q) => $q->where('faculte_id', $request->user()->faculteRestreinteId()))
            ->pluck('id')
            ->all();
    }

    /** @return Collection<int, Group> */
    private function groupesScopés(Request $request)
    {
        return Group::orderBy('code')
            ->when($request->user()->faculteRestreinteId() !== null, fn ($q) => $q->where('faculte_id', $request->user()->faculteRestreinteId()))
            ->get();
    }

    private function verifierScope(Request $request, AssignmentSimulation $simulation): void
    {
        $restreinte = $request->user()->faculteRestreinteId();

        if ($restreinte === null) {
            return;
        }

        $groupes = Group::whereIn('id', $simulation->parametres['groupe_ids'] ?? [])->pluck('faculte_id');

        abort_if($groupes->contains(fn ($f) => (int) $f !== $restreinte), 404);
    }
}
