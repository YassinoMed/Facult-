<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ExecuterAffectationMasseLot;
use App\Models\AffectationGroupe;
use App\Models\AffectationMasseLot;
use App\Models\AffectationProposal;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Services\Affectations\AssignmentConstraintEngine;
use App\Services\Affectations\MassAssignmentService;
use App\Services\AffectationService;
use App\Services\ChangementGroupeService;
use App\Services\InscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Affectations étudiant → groupe (§17-18) : capacité, unicité active,
 * clôture historisée. Le workflow = demande motivée + validation par
 * rôle autorisé (traçée : auteur, motif, date).
 */
class AffectationController extends Controller
{
    public function __construct(
        private readonly AffectationService $affectations,
        private readonly MassAssignmentService $massAssignment,
    ) {}

    public function store(Request $request, Etudiant $etudiant): RedirectResponse
    {
        Gate::authorize('update', $etudiant);

        $valide = $request->validate([
            'groupe_id' => 'required|exists:groups,id',
            'annee_universitaire_id' => 'required|exists:annees_universitaires,id',
            'sous_groupe_id' => 'nullable|exists:sous_groupes,id',
            'motif' => 'required|string|max:255',
            'force' => 'sometimes|boolean',
        ]);

        $groupe = Group::findOrFail($valide['groupe_id']);
        abort_unless($request->user()->peutAccederFaculte((int) $groupe->faculte_id), 403);
        abort_unless($request->user()->peutAccederFaculte((int) $etudiant->faculte_id), 403);

        try {
            $this->affectations->affecter(
                $etudiant,
                $groupe,
                AnneeUniversitaire::findOrFail($valide['annee_universitaire_id']),
                (int) $request->user()->id,
                $valide['motif'],
                (bool) ($valide['force'] ?? false),
                $valide['sous_groupe_id'] ?? null,
            );
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['groupe' => $e->getMessage()])->withInput();
        }

        return redirect()->route('admin.etudiants.show', $etudiant)
            ->with('success', "Étudiant affecté au groupe {$groupe->code} (historique conservé).");
    }

    /**
     * Prévisualisation : contraintes + impacts AVANT confirmation.
     */
    public function previsualiser(Request $request, Etudiant $etudiant): View
    {
        Gate::authorize('view', $etudiant);

        $donnees = $request->validate([
            'groupe_id' => 'required|exists:groups,id',
            'annee_universitaire_id' => 'required|exists:annees_universitaires,id',
        ]);

        $groupe = Group::findOrFail($donnees['groupe_id']);
        abort_unless($request->user()->peutAccederFaculte((int) $groupe->faculte_id), 404);
        $annee = AnneeUniversitaire::findOrFail($donnees['annee_universitaire_id']);

        return view('admin.etudiants.affectation-preview', [
            'etudiant' => $etudiant,
            'groupe' => $groupe,
            'annee' => $annee,
            'contraintes' => $this->affectations->controler($etudiant, $groupe, $annee),
            'impacts' => $this->affectations->impacts($etudiant, $groupe),
        ]);
    }

    public function retirer(Request $request, Etudiant $etudiant): RedirectResponse
    {
        Gate::authorize('update', $etudiant);

        $valide = $request->validate([
            'affectation_id' => 'required|exists:affectations_groupe,id',
            'motif' => 'required|string|max:255',
        ]);

        $affectation = AffectationGroupe::findOrFail($valide['affectation_id']);
        abort_unless((int) $affectation->etudiant_id === (int) $etudiant->id, 404);

        try {
            $this->affectations->retirer($affectation, (int) $request->user()->id, $valide['motif']);
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['affectation' => $e->getMessage()]);
        }

        return redirect()->route('admin.etudiants.show', $etudiant)
            ->with('success', 'Affectation retirée (historique conservé).');
    }

    /**
     * LOOP B (§9) — Changement de groupe : workflow distinct de
     * l'affectation initiale (idempotence, historique, audit, notif).
     * Mêmes Gates et périmètre faculté que les affectations.
     */
    public function changer(Request $request, Etudiant $etudiant): RedirectResponse
    {
        Gate::authorize('update', $etudiant);

        $valide = $request->validate([
            'groupe_id' => 'required|exists:groups,id',
            'annee_universitaire_id' => 'required|exists:annees_universitaires,id',
            'motif' => 'required|string|max:255',
        ]);

        $groupe = Group::findOrFail($valide['groupe_id']);
        abort_unless($request->user()->peutAccederFaculte((int) $groupe->faculte_id), 403);
        abort_unless($request->user()->peutAccederFaculte((int) $etudiant->faculte_id), 403);

        $resultat = app(ChangementGroupeService::class)->changer(
            $etudiant,
            $groupe,
            AnneeUniversitaire::findOrFail($valide['annee_universitaire_id']),
            (int) $request->user()->id,
            $valide['motif'],
        );

        if (! $resultat['effectue']) {
            return redirect()->back()->withErrors(['groupe' => $resultat['raison']])->withInput();
        }

        return redirect()->route('admin.etudiants.show', $etudiant)
            ->with('success', "Groupe changé vers {$groupe->code} (ancienne affectation conservée dans l'historique).");
    }

    public function masse(Request $request): View
    {
        Gate::authorize('viewAny', Etudiant::class);

        $faculteId = $request->user()->faculteRestreinteId();

        $facultes = Faculte::where('active', true)
            ->when($faculteId !== null, fn ($q) => $q->where('id', $faculteId))
            ->orderBy('nom')
            ->get();

        $programmes = Programme::where('actif', true)
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->orderBy('code')
            ->get();

        $niveaux = Niveau::where('actif', true)
            ->orderBy('ordre')
            ->get();

        if ($niveaux->isEmpty()) {
            $niveaux = collect(['L1', 'L2', 'L3', 'M1', 'M2'])->map(fn ($code) => (object) ['code' => $code, 'nom' => $code]);
        }

        $anneeDefautId = $this->anneeDefaut();

        $nonAffectesCount = Etudiant::where('statut', 'valide')
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->whereDoesntHave('affectations', function ($q) use ($anneeDefautId) {
                if ($anneeDefautId) {
                    $q->where('annee_universitaire_id', $anneeDefautId);
                }
                $q->where('statut', 'active');
            })
            ->count();

        $propositionsCount = AffectationProposal::enAttente()
            ->when($faculteId !== null, fn ($q) => $q->whereHas('student', fn ($s) => $s->where('faculte_id', $faculteId)))
            ->count();

        return view('admin.affectations.masse', [
            'facultes' => $facultes,
            'programmes' => $programmes,
            'niveaux' => $niveaux,
            'groupes' => Group::with('programme')->orderBy('code')
                ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
                ->get(),
            'annees' => AnneeUniversitaire::orderByDesc('libelle')->get(),
            'anneeDefaut' => $anneeDefautId,
            'nonAffectesCount' => $nonAffectesCount,
            'nonAffectesIds' => $this->nonAffectesTexte($faculteId),
            'propositionsCount' => $propositionsCount,
        ]);
    }

    /**
     * Analyse et simulation intelligente en masse sans écriture (LOOP 5 & 6).
     */
    public function analyserMasse(Request $request): View|JsonResponse
    {
        Gate::authorize('viewAny', Etudiant::class);

        $valide = $request->validate([
            'annee_universitaire_id' => 'required|exists:annees_universitaires,id',
            'faculte_id' => 'nullable|exists:facultes,id',
            'programme_id' => 'nullable|exists:programmes,id',
            'niveau' => 'nullable|string|max:20',
            'uniquement_non_affectes' => 'nullable|boolean',
            'etudiant_ids_text' => 'nullable|string|max:60000',
        ]);

        $colles = $this->extraireIds((string) ($valide['etudiant_ids_text'] ?? ''));
        $criteres = [
            'annee_universitaire_id' => (int) $valide['annee_universitaire_id'],
            'faculte_id' => ! empty($valide['faculte_id']) ? (int) $valide['faculte_id'] : null,
            'programme_id' => ! empty($valide['programme_id']) ? (int) $valide['programme_id'] : null,
            'niveau' => $valide['niveau'] ?? null,
            'uniquement_non_affectes' => $request->boolean('uniquement_non_affectes', true),
            'etudiant_ids' => $colles !== [] ? $colles : null,
            'etudiant_ids_text' => (string) ($valide['etudiant_ids_text'] ?? ''),
        ];

        $analyse = $this->massAssignment->analyser($criteres, $request->user());

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json($analyse);
        }

        $annee = AnneeUniversitaire::findOrFail($criteres['annee_universitaire_id']);

        return view('admin.affectations.masse-simulation', [
            'analyse' => $analyse,
            'annee' => $annee,
            'criteres' => $criteres,
        ]);
    }

    /**
     * Régularisation en masse des inscriptions académiques manquantes depuis le cockpit.
     */
    public function regulariserInscriptionsMasse(Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Etudiant::class);

        $valide = $request->validate([
            'annee_universitaire_id' => 'required|exists:annees_universitaires,id',
            'faculte_id' => 'nullable|exists:facultes,id',
        ]);

        $annee = AnneeUniversitaire::findOrFail((int) $valide['annee_universitaire_id']);
        $faculteId = $request->user()->faculteRestreinteId() ?? (! empty($valide['faculte_id']) ? (int) $valide['faculte_id'] : null);

        $resultat = app(InscriptionService::class)->regulariserInscriptionsManquantes(
            $annee->id,
            $faculteId,
            $request->user()->id,
        );

        return redirect()->route('admin.affectations.masse')
            ->with('success', "{$resultat['crees']} inscription(s) académique(s) régularisée(s) avec succès pour l'année {$annee->libelle}.");
    }

    /**
     * Confirmation transactionnelle de masse avec lockForUpdate (LOOP 8, 9 & 10).
     */
    public function confirmerMasse(Request $request): RedirectResponse|JsonResponse
    {
        Gate::authorize('viewAny', Etudiant::class);

        $donnees = $request->validate([
            'annee_universitaire_id' => 'required|exists:annees_universitaires,id',
            'motif' => 'required|string|max:255',
            'force' => 'nullable|boolean',
            'confirmer' => 'required|accepted',
            'selections' => 'nullable|array|max:5000',
            'selections.*.student_id' => 'required_with:selections|integer|exists:etudiants,id',
            'selections.*.groupe_id' => 'required_with:selections|integer|exists:groups,id',
            'selections_json' => 'nullable|string|max:500000',
        ]);

        $selections = $donnees['selections'] ?? [];
        if (! empty($donnees['selections_json'])) {
            $decoded = json_decode($donnees['selections_json'], true);
            if (is_array($decoded)) {
                $selections = $decoded;
            }
        }

        abort_if(empty($selections), 422, 'Aucune proposition d’affectation sélectionnée.');

        $force = (bool) ($donnees['force'] ?? false);
        if ($force) {
            abort_unless($request->user()->hasPermission('groupes.manage'), 403, 'Forcer une affectation requiert groupes.manage.');
        }

        // LOOP P2 : au-delà du seuil mesuré, exécution différée par
        // lot (tranches + progression + reprise) plutôt qu'HTTP long.
        $seuilSync = max(1, (int) config('affectations.lots.seuil_sync', 1000));
        if (count($selections) > $seuilSync) {
            $lot = $this->massAssignment->creerLot(
                $selections,
                (int) $donnees['annee_universitaire_id'],
                (int) $request->user()->id,
                $donnees['motif'],
                $force
            );

            ExecuterAffectationMasseLot::dispatch($lot->id);

            if ($request->wantsJson()) {
                return response()->json(['lot_id' => $lot->id, 'reference' => $lot->reference] + $lot->progression());
            }

            return redirect()->route('admin.affectations.masse.lots.show', $lot)
                ->with('info', "Volume important ({$lot->total}) : exécution en arrière-plan, progression suivie ici.");
        }

        $rapport = $this->massAssignment->confirmer(
            $selections,
            (int) $donnees['annee_universitaire_id'],
            $request->user(),
            $donnees['motif'],
            $force
        );

        if ($request->wantsJson()) {
            return response()->json($rapport);
        }

        $message = "Affectation en masse terminée : {$rapport['affectes']} étudiant(s) affecté(s).";
        if ($rapport['ignores'] > 0) {
            $message .= " ({$rapport['ignores']} ignoré(s) ou reporté(s)).";
        }

        return redirect()->route('admin.affectations.masse')
            ->with('success', $message)
            ->with('rapport_masse', [
                'affectes' => $rapport['affectes'],
                'ignores' => $rapport['ignores'],
                'total' => $rapport['total_demande'],
                'erreurs' => array_slice($rapport['erreurs'], 0, 10, true),
                'erreurs_supplementaires' => max(0, count($rapport['erreurs']) - 10),
            ]);
    }

    /**
     * Suivi d'un lot (LOOP P2) : progression JSON ou page avec
     * rafraîchissement. Périmètre : global = tout, restreint = ses
     * propres lots uniquement (le lot ne porte pas de faculté).
     */
    public function showLot(Request $request, AffectationMasseLot $lot): View|JsonResponse
    {
        Gate::authorize('viewAny', Etudiant::class);

        if ($request->user()->faculteRestreinteId() !== null
            && (int) $lot->auteur_id !== (int) $request->user()->id) {
            abort(404);
        }

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(
                ['id' => $lot->id, 'reference' => $lot->reference] + $lot->progression()
            );
        }

        return view('admin.affectations.masse-lot', [
            'lot' => $lot->loadMissing('anneeUniversitaire'),
            'progression' => $lot->progression(),
            'groupes' => $lot->codesGroupes(),
        ]);
    }

    /**
     * Annulation coopérative (LOOP P2) : le Job en cours s'arrête à
     * la tranche suivante, le passé reste (historique conservé).
     */
    public function annulerLot(Request $request, AffectationMasseLot $lot): RedirectResponse|JsonResponse
    {
        Gate::authorize('viewAny', Etudiant::class);

        $estAuteur = (int) $lot->auteur_id === (int) $request->user()->id;
        abort_unless($estAuteur || $request->user()->hasPermission('archives.delete'), 403);
        abort_if(! $lot->estModifiable(), 422, 'Lot déjà clôturé.');

        $lot->update(['statut' => AffectationMasseLot::STATUT_ANNULE]);

        if ($request->wantsJson()) {
            return response()->json(['statut' => $lot->statut]);
        }

        return redirect()->route('admin.affectations.masse.lots.show', $lot)
            ->with('info', "Lot {$lot->reference} annulé : {$lot->affectes} affectation(s) conservée(s).");
    }

    /**
     * Enregistrement en masse des propositions semi-automatiques (LOOP 7 & 11).
     */
    public function proposerMasse(Request $request): RedirectResponse|JsonResponse
    {
        Gate::authorize('viewAny', Etudiant::class);

        $donnees = $request->validate([
            'annee_universitaire_id' => 'required|exists:annees_universitaires,id',
            'motif' => 'nullable|string|max:255',
            'selections' => 'nullable|array|max:5000',
            'selections.*.student_id' => 'required_with:selections|integer|exists:etudiants,id',
            'selections.*.groupe_id' => 'required_with:selections|integer|exists:groups,id',
            'selections_json' => 'nullable|string|max:500000',
        ]);

        $selections = $donnees['selections'] ?? [];
        if (! empty($donnees['selections_json'])) {
            $decoded = json_decode($donnees['selections_json'], true);
            if (is_array($decoded)) {
                $selections = $decoded;
            }
        }

        abort_if(empty($selections), 422, 'Aucune proposition d’affectation sélectionnée.');

        $motif = $donnees['motif'] ?? 'Propositions générées via cockpit de masse';

        $rapport = $this->massAssignment->creerPropositions(
            $selections,
            (int) $donnees['annee_universitaire_id'],
            $request->user(),
            $motif
        );

        if ($request->wantsJson()) {
            return response()->json($rapport);
        }

        $message = "Génération terminée : {$rapport['creees']} proposition(s) enregistrée(s) en attente de validation.";

        return redirect()->route('admin.affectations.propositions.index')
            ->with('success', $message);
    }

    /**
     * Recherche serveur de candidats pour le cockpit (§6) : CIN,
     * matricule, nom, prénom, email, téléphone — périmètre faculté
     * strict, 20 résultats max, jamais de sélection automatique
     * (la liste est affichée, l'administrateur ajoute les IDs).
     */
    public function rechercherCandidats(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Etudiant::class);

        $valide = $request->validate([
            'q' => 'required|string|min:2|max:60',
        ]);

        $motif = '%'.str_replace(['%', '_'], '', trim($valide['q'])).'%';
        $faculteRestreinteId = $request->user()->faculteRestreinteId();

        $resultats = Etudiant::query()
            ->where('statut', 'valide')
            ->when($faculteRestreinteId !== null, fn ($q) => $q->where('faculte_id', $faculteRestreinteId))
            ->where(fn ($q) => $q
                ->where('cin', 'like', $motif)
                ->orWhere('num_inscription', 'like', $motif)
                ->orWhere('nom', 'like', $motif)
                ->orWhere('prenom', 'like', $motif)
                ->orWhere('email', 'like', $motif)
                ->orWhere('telephone', 'like', $motif))
            ->with('programmeRef')
            ->orderBy('nom')
            ->orderBy('prenom')
            ->limit(20)
            ->get()
            ->map(fn (Etudiant $e) => [
                'id' => $e->id,
                'nom' => $e->nom,
                'prenom' => $e->prenom,
                'cin_masquee' => $e->cin_masquee,
                'num_inscription' => $e->num_inscription,
                'niveau' => $e->niveau,
                'programme' => $e->programmeRef?->nom,
            ]);

        return response()->json(['resultats' => $resultats, 'total' => $resultats->count()]);
    }

    /**
     * Export CSV du rapport d'analyse (§39) : rejoue l'analyse avec
     * les mêmes critères (déterministe) et diffuse propositions +
     * blocages. Même périmètre que l'analyse, gate d'export dédiée.
     */
    public function exporterMasse(Request $request)
    {
        $valide = $request->validate([
            'annee_universitaire_id' => 'required|exists:annees_universitaires,id',
            'faculte_id' => 'nullable|exists:facultes,id',
            'programme_id' => 'nullable|exists:programmes,id',
            'niveau' => 'nullable|string|max:20',
            'uniquement_non_affectes' => 'nullable|boolean',
            'etudiant_ids_text' => 'nullable|string|max:60000',
        ]);

        $colles = $this->extraireIds((string) ($valide['etudiant_ids_text'] ?? ''));
        $criteres = [
            'annee_universitaire_id' => (int) $valide['annee_universitaire_id'],
            'faculte_id' => ! empty($valide['faculte_id']) ? (int) $valide['faculte_id'] : null,
            'programme_id' => ! empty($valide['programme_id']) ? (int) $valide['programme_id'] : null,
            'niveau' => $valide['niveau'] ?? null,
            'uniquement_non_affectes' => $request->boolean('uniquement_non_affectes', true),
            'etudiant_ids' => $colles !== [] ? $colles : null,
        ];

        $analyse = $this->massAssignment->analyser($criteres, $request->user());
        $annee = AnneeUniversitaire::findOrFail($criteres['annee_universitaire_id']);
        $nomFichier = 'affectations-masse-'.preg_replace('/[^A-Za-z0-9-_]/', '_', $annee->libelle).'-'.now()->format('Ymd-His').'.csv';

        $lignesProposees = $analyse['propositions'];
        $lignesBloquees = $analyse['anomalies'];

        return response()->streamDownload(function () use ($lignesProposees, $lignesBloquees) {
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF");
            fputcsv($sortie, [
                'num_inscription', 'nom', 'prenom', 'cin', 'niveau', 'programme',
                'groupe_actuel', 'groupe_propose', 'score', 'certitude', 'statut', 'motif',
            ], ';');

            foreach ($lignesProposees as $p) {
                fputcsv($sortie, [
                    $p['student_numero'], $p['student_nom'], $p['student_prenom'], $p['student_cin'],
                    $p['niveau'], $p['programme'], $p['current_group'] ?? '',
                    $p['proposed_group_code'], $p['score'], $p['certitude'], 'propose',
                    implode(' | ', $p['warnings']),
                ], ';');
            }

            foreach ($lignesBloquees as $a) {
                fputcsv($sortie, [
                    '', $a['student_nom'], '', '', '', '', '', '', '', '', 'bloque',
                    "[{$a['code']}] {$a['message']}",
                ], ';');
            }

            fclose($sortie);
        }, $nomFichier, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function simuler(Request $request): View
    {
        Gate::authorize('viewAny', Etudiant::class);

        $donnees = $request->validate([
            'groupe_id' => 'required|exists:groups,id',
            'annee_universitaire_id' => 'required|exists:annees_universitaires,id',
            'etudiant_ids_text' => 'required|string|max:60000',
        ]);

        $idsBruts = $this->extraireIds($donnees['etudiant_ids_text']);

        $groupe = Group::findOrFail($donnees['groupe_id']);
        abort_unless($request->user()->peutAccederFaculte((int) $groupe->faculte_id), 404);

        // Cloisonnement : seuls les étudiants du périmètre sont simulés.
        $ids = $this->idsDansPerimetre($idsBruts, $request->user());

        $annee = AnneeUniversitaire::findOrFail($donnees['annee_universitaire_id']);
        $etudiants = Etudiant::whereIn('id', $ids)->orderBy('nom')->get();

        return view('admin.affectations.simulation', [
            'groupe' => $groupe,
            'annee' => $annee,
            'simulation' => $this->affectations->simuler($ids, $groupe, $annee),
            'etudiants' => $etudiants->keyBy('id'),
            'pistes' => $this->pistes($etudiants, collect([$groupe]), (int) $annee->id),
        ]);
    }

    public function appliquer(Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Etudiant::class);

        $donnees = $request->validate([
            'groupe_id' => 'required|exists:groups,id',
            'annee_universitaire_id' => 'required|exists:annees_universitaires,id',
            'etudiant_ids' => 'required|array|max:5000',
            'etudiant_ids.*' => 'integer|min:1',
            'motif' => 'required|string|max:255',
            'force' => 'sometimes|boolean',
            'confirmer' => 'required|accepted',
        ]);

        $groupe = Group::findOrFail($donnees['groupe_id']);
        abort_unless($request->user()->peutAccederFaculte((int) $groupe->faculte_id), 404);
        abort_if((bool) ($donnees['force'] ?? false) && ! $request->user()->hasPermission('groupes.manage'), 403);

        $ids = $this->idsDansPerimetre($donnees['etudiant_ids'], $request->user());

        $resultat = $this->affectations->affecterLot(
            $ids, $groupe,
            AnneeUniversitaire::findOrFail($donnees['annee_universitaire_id']),
            (int) $request->user()->id,
            $donnees['motif'],
            (bool) ($donnees['force'] ?? false),
        );

        return redirect()->route('admin.affectations.masse')
            ->with('success', "{$resultat['appliquees']} affectation(s) appliquée(s), {$resultat['ignorees']} ignorée(s).");
    }

    /**
     * Affectation automatique (§14) : formulaire puis simulation SANS
     * écriture (équilibrage par places). Confirmation via appliquerAuto.
     */
    public function auto(Request $request): View
    {
        Gate::authorize('viewAny', Etudiant::class);

        return view('admin.affectations.auto', [
            'groupes' => Group::orderBy('code')
                ->when($request->user()->faculteRestreinteId() !== null,
                    fn ($q) => $q->where('faculte_id', $request->user()->faculteRestreinteId()))
                ->withCount(['affectations as affectations_actives' => fn ($q) => $q->where('statut', 'active')])
                ->get(),
            'annees' => AnneeUniversitaire::orderByDesc('libelle')->get(),
            'anneeDefaut' => $this->anneeDefaut(),
            'nonAffectesIds' => $this->nonAffectesTexte($request->user()->faculteRestreinteId()),
        ]);
    }

    public function simulerAuto(Request $request): RedirectResponse|View
    {
        Gate::authorize('viewAny', Etudiant::class);

        $donnees = $request->validate([
            'groupe_ids' => 'required|array|min:1|max:500',
            'groupe_ids.*' => 'integer|exists:groups,id',
            'annee_universitaire_id' => 'required|exists:annees_universitaires,id',
            'etudiant_ids_text' => 'required|string|max:60000',
        ]);

        $idsBruts = $this->extraireIds($donnees['etudiant_ids_text']);
        $ids = $this->idsDansPerimetre($idsBruts, $request->user());

        $groupes = Group::whereIn('id', $donnees['groupe_ids'])
            ->when($request->user()->faculteRestreinteId() !== null,
                fn ($q) => $q->where('faculte_id', $request->user()->faculteRestreinteId()))
            ->get();
        abort_if($groupes->isEmpty(), 404);

        $annee = AnneeUniversitaire::findOrFail($donnees['annee_universitaire_id']);

        $etudiantsListe = Etudiant::whereIn('id', $ids)->orderBy('nom')->get();

        // Pré-check §22 : zéro potentiellement éligible = pas d'algorithme.
        $precheck = $this->affectations->precheck($ids, $groupes->pluck('id')->all(), $annee);

        if ($precheck['eligibles'] === 0) {
            return back()->withErrors(['simulation' => "Aucun étudiant éligible : {$precheck['sans_inscription']} sans inscription {$annee->libelle}, "
                .count($ids) - $precheck['prog_compatibles'].' formation incompatible, '
                .count($ids) - $precheck['niveau_compatibles'].' niveau incompatible, '
                ."{$precheck['deja_affectes']} déjà affecté(s), {$precheck['verrouilles']} verrouillé(s). Corrigez les données (pistes affichées) puis relancez."])->withInput();
        }

        return view('admin.affectations.auto-simulation', [
            'groupes' => $groupes,
            'annee' => $annee,
            'precheck' => $precheck,
            'proposition' => $this->affectations->repartitionAutomatique($ids, $groupes->pluck('id')->all(), $annee),
            'etudiants' => $etudiantsListe->keyBy('id'),
            'pistes' => $this->pistes($etudiantsListe, $groupes, (int) $annee->id),
        ]);
    }

    /**
     * Application par TRANCHES (500/requête) : sans worker, chaque
     * requête reste sous les timeouts ; le reliquat est réaffiché
     * pour confirmation explicite de la suite.
     */
    public function appliquerAuto(Request $request): RedirectResponse|View
    {
        Gate::authorize('viewAny', Etudiant::class);

        $donnees = $request->validate([
            'annee_universitaire_id' => 'required|exists:annees_universitaires,id',
            'repartition' => 'nullable|array|max:500',
            'repartition.*' => 'array|max:5000',
            'repartition.*.*' => 'integer|min:1',
            'repartition_json' => 'nullable|string|max:500000',
            'motif' => 'required|string|max:255',
            'confirmer' => 'required|accepted',
        ]);

        // UN SEUL champ JSON (vue) : contourne max_input_vars qui
        // tronquerait N champs cachés en silence. Le tableau reste
        // accepté (compatibilité + tests).
        $repartition = $donnees['repartition'] ?? $this->decoderRepartition((string) ($donnees['repartition_json'] ?? ''));

        abort_if($repartition === [], 422, 'Répartition vide ou illisible.');

        $annee = AnneeUniversitaire::findOrFail($donnees['annee_universitaire_id']);

        $appliques = $this->appliquerTranches($repartition, $annee, $request->user(), $donnees['motif']);

        if ($appliques['resteParGroupe'] === []) {
            return redirect()->route('admin.affectations.auto')
                ->with('success', "{$appliques['total']} affectation(s) automatique(s) appliquée(s), {$appliques['ignorees']} ignorée(s).");
        }

        return $this->vueReliquat($appliques['resteParGroupe'], $annee, $appliques['total'], $appliques['ignorees']);
    }

    /**
     * Applique la répartition par tranches de 500 : chaque groupe est
     * re-cloisonné au périmètre puis affecté via le service. Retourne
     * les compteurs + le reliquat à confirmer explicitement.
     *
     * @param  array<int, array<int>>  $repartition
     * @return array{total: int, ignorees: int, resteParGroupe: array<int, array<int>>}
     */
    private function appliquerTranches(array $repartition, AnneeUniversitaire $annee, User $auteur, string $motif, int $limite = 500): array
    {
        $total = 0;
        $ignorees = 0;
        $traites = 0;
        $resteParGroupe = [];

        foreach ($repartition as $groupeId => $etudiantIds) {
            $groupe = Group::findOrFail((int) $groupeId);
            abort_unless($auteur->peutAccederFaculte((int) $groupe->faculte_id), 404);

            $ids = $this->idsDansPerimetre($etudiantIds, $auteur);

            $lot = array_slice($ids, 0, max(0, $limite - $traites));
            $traites += count($lot);

            if ($lot !== []) {
                $resultat = $this->affectations->affecterLot($lot, $groupe, $annee, (int) $auteur->id, $motif);
                $total += $resultat['appliquees'];
                $ignorees += $resultat['ignorees'];
            }

            $restants = array_values(array_diff($ids, $lot));

            if ($restants !== []) {
                $resteParGroupe[$groupeId] = $restants;
            }

            if ($traites >= $limite) {
                break;
            }
        }

        return ['total' => $total, 'ignorees' => $ignorees, 'resteParGroupe' => $resteParGroupe];
    }

    /**
     * Vue de reprise du reliquat : mêmes données que la simulation
     * (groupes, proposition restante, pistes) + message de tranche.
     *
     * @param  array<int, array<int>>  $resteParGroupe
     */
    private function vueReliquat(array $resteParGroupe, AnneeUniversitaire $annee, int $total, int $ignorees): View
    {
        $groupes = Group::whereIn('id', array_keys($resteParGroupe))->orderBy('code')->get();
        $idsRestants = array_merge(...array_values($resteParGroupe));
        $etudiantsListe = Etudiant::whereIn('id', $idsRestants)->orderBy('nom')->get();
        $capacites = [];

        foreach ($groupes as $groupe) {
            $effectif = $groupe->effectifActuel();
            $capacites[$groupe->id] = ['places' => $groupe->capacite !== null ? max(0, (int) $groupe->capacite - $effectif) : PHP_INT_MAX, 'effectif' => $effectif, 'capacite' => $groupe->capacite];
        }

        return view('admin.affectations.auto-simulation', [
            'groupes' => $groupes,
            'annee' => $annee,
            'proposition' => ['repartition' => $resteParGroupe, 'non_affectes' => [], 'capacites' => $capacites],
            'etudiants' => $etudiantsListe->keyBy('id'),
            'pistes' => $this->pistes($etudiantsListe, $groupes, (int) $annee->id),
            'trancheMessage' => "Tranche appliquée : {$total} affectation(s), {$ignorees} ignorée(s). Reste ".count($idsRestants).' à appliquer — vérifiez puis confirmez la suite.',
        ]);
    }

    /**
     * IDs re-cloisonnés au périmètre faculté de l'utilisateur
     * (vide = vide, `whereIn([])` ne renvoie rien).
     *
     * @param  array<int>  $idsBruts
     * @return array<int>
     */
    private function idsDansPerimetre(array $idsBruts, User $utilisateur): array
    {
        return Etudiant::whereIn('id', $idsBruts)
            ->when($utilisateur->faculteRestreinteId() !== null,
                fn ($q) => $q->where('faculte_id', $utilisateur->faculteRestreinteId()))
            ->pluck('id')->all();
    }

    /**
     * IDs (un par ligne) des étudiants valides sans affectation active,
     * pré-remplissage des formulaires de masse.
     */
    private function nonAffectesTexte(?int $faculteRestreinteId): string
    {
        return implode("\n", Etudiant::where('statut', 'valide')
            ->whereDoesntHave('affectations', fn ($q) => $q->where('statut', 'active'))
            ->when($faculteRestreinteId !== null,
                fn ($q) => $q->where('faculte_id', $faculteRestreinteId))
            ->orderBy('id')
            ->limit(5000)
            ->pluck('id')
            ->all());
    }

    /** @return array<int> */
    /**
     * Repli GET des simulations POST-only : un refresh ou un accès
     * direct ne doit jamais finir en 405, mais revenir au formulaire.
     */
    public function simulerRediriger(Request $request): RedirectResponse
    {
        return redirect()->route((string) $request->route()->defaults['formulaire'])->with(
            'info',
            'La simulation se lance depuis le formulaire (sans écriture). Veuillez relancer la simulation.'
        );
    }

    /**
     * Année présélectionnée : celle avec le plus d'inscriptions
     * actives (évite une simulation entièrement vide quand l'année
     * la plus récente n'a encore aucune inscription).
     */
    private function anneeDefaut(): ?int
    {
        return AnneeUniversitaire::withCount(['inscriptions as inscriptions_actives' => fn ($q) => $q->whereIn('statut', ['en_cours', 'valide'])])
            ->orderByDesc('inscriptions_actives')
            ->orderByDesc('libelle')
            ->first()?->id;
    }

    /**
     * Pistes d'action par étudiant bloqué (affichage) : inscription
     * manquante sur l'année + groupes compatibles par signature.
     * Une seule requête d'inscriptions, aucun contournement des règles.
     *
     * @param  Collection<int, Etudiant>  $etudiants
     * @param  Collection<int, Group>  $groupes
     * @return array<int, array{sans_inscription: bool, compatibles: array<int, string>}>
     */
    private function pistes($etudiants, $groupes, int $anneeId): array
    {
        $moteur = app(AssignmentConstraintEngine::class);
        $inscrits = $etudiants->isEmpty() ? [] : InscriptionAcademique::where('annee_universitaire_id', $anneeId)
            ->whereIn('etudiant_id', $etudiants->map(fn (Etudiant $e) => $e->id)->all())
            ->pluck('etudiant_id')->all();
        $pistes = [];
        $cache = [];

        foreach ($etudiants as $etudiant) {
            // Bucket par signature : un seul calcul par profil.
            $cle = "{$etudiant->programme_id}|{$etudiant->niveau}|{$etudiant->faculte_id}";

            if (! array_key_exists($cle, $cache)) {
                $cache[$cle] = $moteur->groupesCompatibles($etudiant, $groupes);
            }

            $pistes[$etudiant->id] = [
                'sans_inscription' => ! in_array($etudiant->id, $inscrits, true),
                'compatibles' => $cache[$cle],
            ];
        }

        return $pistes;
    }

    /**
     * Décode la répartition JSON (1 seul champ de formulaire) avec
     * garde-fous structurels stricts (groupes ≤ 500, total ≤ 10000).
     *
     * @return array<int, array<int>>
     */
    private function decoderRepartition(string $json): array
    {
        if ($json === '') {
            return [];
        }

        try {
            $donnees = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (! is_array($donnees) || count($donnees) > 500) {
            return [];
        }

        $repartition = [];
        $total = 0;

        foreach ($donnees as $groupeId => $ids) {
            if (! is_numeric($groupeId) || ! is_array($ids) || count($ids) > 5000) {
                return [];
            }

            $filtres = [];

            foreach ($ids as $id) {
                if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                    return [];
                }

                $filtres[] = (int) $id;
                $total++;

                if ($total > 10000) {
                    return [];
                }
            }

            $repartition[(int) $groupeId] = $filtres;
        }

        return $repartition;
    }

    private function extraireIds(string $texte): array
    {
        preg_match_all('/\d+/', $texte, $correspondances);

        return array_values(array_unique(array_map('intval', $correspondances[0] ?? [])));
    }
}
