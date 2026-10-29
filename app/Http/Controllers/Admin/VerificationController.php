<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\VerifyStudentDossierJob;
use App\Models\DossierCorrection;
use App\Models\DossierVerification;
use App\Models\Etudiant;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Services\AuditService;
use App\Services\BlocageInscriptionService;
use App\Services\DossierComplementService;
use App\Services\InscriptionCorrectionService;
use App\Services\InscriptionEligibilityService;
use App\Services\NormalisationService;
use App\Services\StudentStatusService;
use App\Services\Verification\CorrectionService;
use App\Services\Verification\VerificationEngine;
use App\Services\Verification\VerificationStore;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * Vérification intelligente du dossier étudiant (§ vérificateur).
 *
 * Moteur d'analyse automatisé, non-destructif, explicable.
 * Fournit détection d'anomalies, score, suggestions de correction
 * assistée et audit complet de chaque action.
 */
class VerificationController extends Controller
{
    public function __construct(
        private readonly VerificationEngine $engine,
        private readonly AuditService $audit,
        private readonly CorrectionService $corrections,
        private readonly VerificationStore $runs,
    ) {}

    /**
     * Consulter le rapport de vérification (HTML ou JSON).
     */
    public function show(Request $request, Etudiant $etudiant): View|JsonResponse
    {
        $this->autoriserAccesEtudiant($request, $etudiant);

        $resultat = $this->engine->verifier($etudiant);

        if ($request->wantsJson()) {
            return response()->json($resultat->toArray());
        }

        $derniereInscription = $etudiant->inscriptionsAcademiques()->latest('annee_universitaire_id')->first();
        $blocages = $derniereInscription !== null
            ? app(BlocageInscriptionService::class)->blocages($derniereInscription)
            : null;

        // LOOP A (automatisation §6) : synthèse d'éligibilité structurée —
        // façade en lecture seule, agrège période + blocages + matrice.
        $syntheseEligibilite = $derniereInscription !== null
            ? app(InscriptionEligibilityService::class)->evaluer($derniereInscription)
            : null;

        return view('admin.verification.show', [
            'etudiant' => $etudiant,
            'resultat' => $resultat,
            'derniereInscription' => $derniereInscription,
            'blocages' => $blocages,
            'syntheseEligibilite' => $syntheseEligibilite,
            'programmes' => Programme::where('faculte_id', $etudiant->faculte_id)->where('actif', true)->orderBy('nom')->get(),
            'niveaux' => Niveau::where('actif', true)->orderBy('ordre')->get(),
            'corrections' => DossierCorrection::where('etudiant_id', $etudiant->id)
                ->whereIn('statut', ['proposee', 'approuvee'])
                ->latest('id')
                ->get(),
        ]);
    }

    /**
     * Déclencher une vérification unitaire et auditer le résultat.
     */
    public function verifier(Request $request, Etudiant $etudiant): RedirectResponse|JsonResponse
    {
        Gate::authorize('update', $etudiant);
        $this->autoriserAccesEtudiant($request, $etudiant);

        $resultat = $this->engine->verifier($etudiant);

        // Run persisté (§13) + audit de l'action de vérification.
        $this->runs->enregistrer($etudiant, $resultat, 'web', (int) $request->user()->id);
        $this->audit->tracer(
            $etudiant,
            HistoriqueAcademique::ACTION_VERIFICATION_DOSSIER,
            'etudiant',
            $etudiant->id,
            null,
            [
                'verdict' => $resultat->verdict,
                'score' => $resultat->score,
                'anomalies_count' => count($resultat->anomalies),
                'duree_ms' => $resultat->dureeMs,
            ],
            'web'
        );

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Vérification effectuée avec succès.',
                'resultat' => $resultat->toArray(),
            ]);
        }

        return redirect()->route('admin.verification.show', $etudiant)
            ->with('status', "Vérification effectuée : dossier {$resultat->verdict} (Score : {$resultat->score}/100)");
    }

    /**
     * Proposer une correction (sans écriture métier, §15).
     */
    public function proposer(Request $request, Etudiant $etudiant): RedirectResponse|JsonResponse
    {
        Gate::authorize('update', $etudiant);
        $this->autoriserAccesEtudiant($request, $etudiant);

        $donnees = $request->validate([
            'champ' => ['required', 'string', 'in:'.implode(',', DossierCorrection::CHAMPS)],
            'nouvelle_valeur' => ['nullable', 'string', 'max:255'],
            'motif' => ['required', 'string', 'max:500'],
            'regle' => ['nullable', 'string', 'max:80'],
        ]);

        try {
            $correction = $this->corrections->proposer(
                $etudiant, $donnees['champ'], $donnees['nouvelle_valeur'] ?? null,
                $donnees['motif'], $donnees['regle'] ?? null, (int) $request->user()->id
            );
        } catch (DomainException $e) {
            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => $e->getMessage()], 422)
                : back()->withErrors(['correction' => $e->getMessage()]);
        }

        return $request->wantsJson()
            ? response()->json(['success' => true, 'correction_id' => $correction->id])
            : redirect()->route('admin.verification.show', $etudiant)->with('status', "Correction #{$correction->id} proposée (en attente de validation).");
    }

    /**
     * Demande de complément adressée à l'étudiant (LOOP 11).
     */
    public function demanderComplement(Request $request, Etudiant $etudiant, DossierComplementService $service): RedirectResponse|JsonResponse
    {
        Gate::authorize('update', $etudiant);
        $this->autoriserAccesEtudiant($request, $etudiant);

        $donnees = $request->validate([
            'champs' => ['nullable', 'array'],
            'champs.*.champ' => ['required_with:champs', 'string', 'in:'.implode(',', DossierCorrection::CHAMPS)],
            'champs.*.motif' => ['required_with:champs', 'string', 'max:500'],
            'champs.*.nouvelle_valeur' => ['nullable', 'string', 'max:255'],
            'pieces' => ['nullable', 'array'],
            'pieces.*.document_id' => ['required_with:pieces', 'integer'],
            'pieces.*.motif' => ['required_with:pieces', 'string', 'max:500'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $bilan = $service->demanderComplement(
                $etudiant,
                $request->user(),
                $donnees['champs'] ?? [],
                $donnees['pieces'] ?? [],
                $donnees['message'] ?? null
            );
        } catch (DomainException $e) {
            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => $e->getMessage()], 422)
                : back()->withErrors(['complement' => $e->getMessage()]);
        }

        $message = "Demande de complément transmise à l'étudiant ({$bilan['champs_demandes']} champ(s), {$bilan['pieces_demandees']} pièce(s)).";

        return $request->wantsJson()
            ? response()->json(['success' => true, 'message' => $message, 'bilan' => $bilan])
            : redirect()->route('admin.verification.show', $etudiant)->with('status', $message);
    }

    /**
     * Approuver / rejeter (4-eyes) / appliquer une correction.
     */
    public function decider(Request $request, DossierCorrection $correction): RedirectResponse|JsonResponse
    {
        $etudiant = $correction->etudiant;
        Gate::authorize('update', $etudiant);
        $this->autoriserAccesEtudiant($request, $etudiant);

        $donnees = $request->validate([
            'decision' => ['required', 'string', 'in:approuver,rejeter,appliquer'],
            'motif' => ['nullable', 'string', 'max:500', 'required_if:decision,rejeter'],
        ]);

        try {
            $resultat = match ($donnees['decision']) {
                'approuver' => $this->corrections->approuver($correction, (int) $request->user()->id, $donnees['motif'] ?? null),
                'rejeter' => $this->corrections->rejeter($correction, (int) $request->user()->id, (string) ($donnees['motif'] ?? '')),
                'appliquer' => $this->corrections->appliquer($correction, (int) $request->user()->id),
            };
        } catch (DomainException $e) {
            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => $e->getMessage()], 422)
                : back()->withErrors(['correction' => $e->getMessage()]);
        }

        $message = $donnees['decision'] === 'appliquer' && is_array($resultat)
            ? "Correction appliquée. Nouveau verdict : {$resultat['verdict_apres']} ({$resultat['score_apres']}/100)."
            : match ($donnees['decision']) {
                'approuver' => 'Correction approuvée.',
                default => 'Correction rejetée.',
            };

        return $request->wantsJson()
            ? response()->json(['success' => true, 'message' => $message])
            : redirect()->route('admin.verification.show', $etudiant)->with('status', $message);
    }

    /**
     * Correction assistée d'un champ avec validation agent et audit (§15).
     *
     * LOOP saisies : validation PAR TYPE DE CHAMP (règles partagées avec
     * le workflow 4-eyes) — la whitelist seule laissait passer une CIN
     * non conforme, un email invalide/doublon, une date arbitraire vers
     * une colonne DATE ou un groupe_id fantôme. `statut` reste
     * corrigeable mais via la transition canonique ; `lieu_naissance` /
     * `nationalite` (colonnes inexistantes) sont retirées.
     */
    public function corriger(Request $request, Etudiant $etudiant): RedirectResponse|JsonResponse
    {
        Gate::authorize('update', $etudiant);
        $this->autoriserAccesEtudiant($request, $etudiant);

        $reglesParChamp = $this->corrections->reglesChampCorrigeable($etudiant);

        $donnees = $request->validate([
            'champ' => ['required', 'string', 'in:'.implode(',', array_keys($reglesParChamp))],
            'nouvelle_valeur' => ['nullable', 'string', 'max:500'],
            'motif' => ['required', 'string', 'max:500'],
        ]);

        $champ = $donnees['champ'];

        // Normalisation contrôlée AVANT validation (email minuscules sans
        // espaces, téléphone canonique) — jamais la CIN (espaces refusés).
        $normalise = app(NormalisationService::class);
        $soumis = $champ === 'email' ? ($normalise->email($donnees['nouvelle_valeur'] ?? null) ?? '')
            : ($donnees['nouvelle_valeur'] ?? null);

        $valideur = Validator::make(
            [$champ => $soumis],
            [$champ => $reglesParChamp[$champ]],
            $this->corrections->messagesChampCorrigeable(),
        );

        // La CIN est l'identifiant clé du dossier : la vider
        // silencieusement (null via chaîne vide) n'est jamais une
        // correction valide.
        if ($champ === 'cin' && ($soumis === null || $soumis === '')) {
            $erreur = 'Le numéro de CIN ne peut pas être vidé.';

            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => $erreur], 422)
                : back()->withErrors([$champ => $erreur])->withInput();
        }

        if ($valideur->fails()) {
            $erreur = (string) $valideur->errors()->first($champ);

            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => $erreur], 422)
                : back()->withErrors([$champ => $erreur])->withInput();
        }

        $ancienneValeur = $etudiant->{$champ};
        $nouvelleValeur = $soumis;

        if ($champ === 'statut') {
            // Transition canonique (§10) : matrice serveur + Situation +
            // events + notification — jamais d'update brut (statut non
            // mass-assignable, no-op silencieux + audit fantôme).
            try {
                app(StudentStatusService::class)->changer($etudiant, (string) $nouvelleValeur, (int) $request->user()->id, $donnees['motif']);
            } catch (DomainException $e) {
                return $request->wantsJson()
                    ? response()->json(['success' => false, 'message' => $e->getMessage()], 422)
                    : back()->withErrors([$champ => $e->getMessage()])->withInput();
            }
        } else {
            DB::transaction(function () use ($etudiant, $champ, $nouvelleValeur, $ancienneValeur, $donnees) {
                $etudiant->update([
                    $champ => $nouvelleValeur,
                ]);

                // Audit de la correction assistée (§16, sans PII non autorisée)
                $this->audit->tracer(
                    $etudiant,
                    HistoriqueAcademique::ACTION_CORRECTION_DOSSIER,
                    'etudiant',
                    $etudiant->id,
                    [$champ => $ancienneValeur],
                    [
                        $champ => $nouvelleValeur,
                        'motif' => $donnees['motif'],
                    ],
                    'web'
                );
            });
        }

        // Re-vérification immédiate après correction
        $nouveauResultat = $this->engine->verifier($etudiant);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Correction appliquée avec succès.',
                'verdict_apres' => $nouveauResultat->verdict,
                'score_apres' => $nouveauResultat->score,
            ]);
        }

        return redirect()->route('admin.verification.show', $etudiant)
            ->with('status', "Champ « {$champ} » corrigé avec succès. Nouveau score : {$nouveauResultat->score}/100");
    }

    /**
     * Lancement d'une vérification en masse par lot asynchrone (§18).
     */
    public function bulk(Request $request): JsonResponse|RedirectResponse
    {
        Gate::authorize('archives.update');
        Gate::authorize('viewAny', Etudiant::class);

        $donnees = $request->validate([
            'etudiant_ids' => ['required', 'array', 'min:1', 'max:500'],
            'etudiant_ids.*' => ['integer', 'exists:etudiants,id'],
            'synchroniser_qualite' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $faculteRestreinteId = $user->faculteRestreinteId();

        // Contrôle périmètre faculté sur l'ensemble des IDs
        $etudiantIds = Etudiant::whereIn('id', $donnees['etudiant_ids'])
            ->when($faculteRestreinteId !== null, fn ($q) => $q->where('faculte_id', $faculteRestreinteId))
            ->pluck('id')
            ->all();

        if (empty($etudiantIds)) {
            return back()->withErrors(['etudiant_ids' => 'Aucun étudiant valide dans votre périmètre.']);
        }

        // Découpage en tranches pour la file d'attente
        $tranches = array_chunk($etudiantIds, 50);
        foreach ($tranches as $tranche) {
            VerifyStudentDossierJob::dispatch(
                $tranche,
                (bool) ($donnees['synchroniser_qualite'] ?? false),
                $user->id
            );
        }

        $message = count($etudiantIds).' dossier(s) envoyé(s) en file de vérification.';

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'count' => count($etudiantIds),
            ]);
        }

        return back()->with('status', $message);
    }

    /**
     * Centre des inscriptions bloquées : derniers runs non conformes,
     * scopés faculté (404 hors périmètre via show).
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Etudiant::class);

        $verdict = $request->validate([
            'verdict' => 'nullable|in:BLOQUANT,A_VERIFIER,CONFORME',
        ])['verdict'] ?? null;

        $query = Etudiant::where('statut', 'en_attente')
            ->whereIn('id', function ($q) use ($verdict) {
                $q->select('etudiant_id')->from('dossier_verifications')
                    ->whereIn('id', function ($qq) {
                        $qq->selectRaw('MAX(id)')->from('dossier_verifications')->groupBy('etudiant_id');
                    })
                    ->when($verdict !== null, fn ($w) => $w->where('verdict', $verdict))
                    ->when($verdict === null, fn ($w) => $w->whereIn('verdict', ['BLOQUANT', 'A_VERIFIER']));
            })->with(['faculte', 'inscriptionsAcademiques' => fn ($q) => $q->latest('annee_universitaire_id')->limit(1)])
            ->latest('id');

        $utilisateur = $request->user();

        if ($utilisateur->faculteRestreinteId() !== null) {
            $query->where('faculte_id', $utilisateur->faculteRestreinteId());
        }

        return view('admin.verification.index', [
            'etudiants' => $query->paginate(25)->withQueryString(),
            'verdict' => $verdict,
            'compteurs' => $this->compteurs($request),
        ]);
    }

    /**
     * Correction contrôlée d'inscription (niveau/programme/type) :
     * motif obligatoire, audit avant/après, re-vérification auto.
     *
     * B8 (LOT 1) : une cible de CHANGEMENT (programme/niveau/type) ne
     * s'écrit plus directement — elle est PROPOSÉE à quatre yeux (un
     * second agent approuve puis applique). Un motif seul (sans
     * modification) reste une trace d'audit directe, inchangée.
     */
    public function corrigerInscription(Request $request, InscriptionAcademique $inscription, InscriptionCorrectionService $corrections): RedirectResponse
    {
        Gate::authorize('update', $inscription);
        abort_unless($request->user()->peutAccederFaculte((int) $inscription->etudiant->faculte_id), 404);

        $valide = $request->validate([
            'programme_id' => 'nullable|integer|exists:programmes,id',
            'niveau_id' => 'nullable|integer|exists:niveaux,id',
            'type' => 'nullable|in:premiere,reinscription,transfert',
            'motif' => 'required|string|min:10|max:500',
        ]);

        $aCibles = array_filter([
            $valide['programme_id'] ?? null,
            $valide['niveau_id'] ?? null,
            $valide['type'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        try {
            if ($aCibles !== []) {
                $record = $this->corrections->proposerInscription($inscription, $valide, (int) $request->user()->id);

                return back()->with('status', "Correction #{$record->id} proposée — approbation par un second agent requise.");
            }

            $resultat = $corrections->corriger($inscription, $valide, (int) $request->user()->id);
        } catch (DomainException $e) {
            return back()->withErrors(['correction' => $e->getMessage()])->withInput();
        }

        return back()->with('status', "Correction enregistrée et re-vérifiée : {$resultat['verdict_avant']} → {$resultat['verdict_apres']} (score {$resultat['score_apres']}).");
    }

    /** @return array{bloquants: int, a_verifier: int, conformes: int} */
    private function compteurs(Request $request): array
    {
        $base = DossierVerification::whereIn('id', function ($q) {
            $q->selectRaw('MAX(id)')->from('dossier_verifications')->groupBy('etudiant_id');
        })->whereHas('etudiant', function ($q) use ($request) {
            $q->where('statut', 'en_attente');

            if ($request->user()->faculteRestreinteId() !== null) {
                $q->where('faculte_id', $request->user()->faculteRestreinteId());
            }
        });

        return [
            'bloquants' => (clone $base)->where('verdict', 'BLOQUANT')->count(),
            'a_verifier' => (clone $base)->where('verdict', 'A_VERIFIER')->count(),
            'conformes' => (clone $base)->where('verdict', 'CONFORME')->count(),
        ];
    }

    /**
     * Contrôle d'accès strict par périmètre faculté et permission (§17).
     */
    private function autoriserAccesEtudiant(Request $request, Etudiant $etudiant): void
    {
        Gate::authorize('view', $etudiant);

        $user = $request->user();
        if (method_exists($user, 'peutAccederFaculte') && ! $user->peutAccederFaculte((int) $etudiant->faculte_id)) {
            abort(403, 'Accès interdit : étudiant hors de votre faculté.');
        }
    }
}
