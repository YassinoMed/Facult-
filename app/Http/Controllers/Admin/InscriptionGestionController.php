<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AffectationGroupe;
use App\Models\AnneeUniversitaire;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Models\InscriptionPedagogique;
use App\Models\Matiere;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\StudentDossiers\Services\Pdf\OfficialDocumentService;
use App\Services\AcademicProgressionService;
use App\Services\AffectationAutomatiqueService;
use App\Services\InscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Inscriptions : validation/rejet/réinscription (administratif) et
 * inscriptions pédagogiques adossées à la maquette (§22-25).
 */
class InscriptionGestionController extends Controller
{
    public function __construct(
        private readonly InscriptionService $inscriptions,
        private readonly AcademicProgressionService $progressions,
    ) {}

    public function valider(Request $request, InscriptionAcademique $inscription): RedirectResponse
    {
        Gate::authorize('update', $inscription);

        $motif = $request->validate(['motif' => 'nullable|string|max:255'])['motif'] ?? null;

        try {
            $this->inscriptions->transiter($inscription, 'valide', (int) $request->user()->id, $motif);
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['statut' => $e->getMessage()]);
        }

        // LOOP §19 : le listener synchrone a déjà tenté l'affectation
        // dans transiter() — lecture du résultat pour affichage immédiat.
        $bilan = $this->lireBilanAuto($inscription->fresh());

        return redirect()->back()->with('success', "Inscription validée (verrouillée). {$bilan}");
    }

    /**
     * Relance manuelle (§26) : mêmes règles, jamais d'écrasement d'un
     * verrou sans exception explicite.
     */
    public function reaffecter(Request $request, InscriptionAcademique $inscription, AffectationAutomatiqueService $automatique): RedirectResponse
    {
        Gate::authorize('update', $inscription);
        abort_unless($request->user()->peutAccederFaculte((int) $inscription->etudiant->faculte_id), 404);
        abort_unless($inscription->statut === 'valide', 422, 'Seule une inscription validée peut déclencher l’affectation automatique.');

        $resultat = $automatique->reessayer(
            $inscription, (int) $request->user()->id, $request->user()->faculteRestreinteId()
        );

        if (in_array($resultat['statut'], ['ASSIGNED', 'EXISTING'], true)) {
            return redirect()->back()->with('success', "Affectation : {$resultat['message']}");
        }

        if ($resultat['statut'] === 'LOCKED') {
            return redirect()->back()->withErrors(['affectation' => $resultat['message']]);
        }

        return redirect()->back()
            ->with('success', 'Inscription conservée (validée).')
            ->withErrors(['affectation' => "Non affecté — {$resultat['raison']} : {$resultat['message']}"]);
    }

    public function rejeter(Request $request, InscriptionAcademique $inscription): RedirectResponse
    {
        Gate::authorize('update', $inscription);

        $valide = $request->validate(['motif' => 'required|string|max:255']);

        try {
            $this->inscriptions->transiter($inscription, 'ajourne', (int) $request->user()->id, $valide['motif']);
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['statut' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Inscription ajournée (motif enregistré).');
    }

    public function reinscrire(Request $request, InscriptionAcademique $inscription): RedirectResponse
    {
        Gate::authorize('update', $inscription);

        $valide = $request->validate([
            'annee_universitaire_id' => 'required|exists:annees_universitaires,id',
            'programme_id' => 'nullable|exists:programmes,id',
            'niveau_id' => 'nullable|exists:niveaux,id',
            'regime' => 'nullable|in:initiale,continue,alternance',
        ]);

        // Isolation faculté sur la CIBLE (audit sécurité 04/10/2026).
        if ($valide['programme_id'] ?? null) {
            $programmeCible = Programme::findOrFail($valide['programme_id']);
            abort_unless($programmeCible->faculte_id === null
                || $request->user()->peutAccederFaculte((int) $programmeCible->faculte_id), 404);
        }

        // Garde anti-doublon : la contrainte unique couvre déjà, message clair ici.
        if ($inscription->etudiant->inscriptionsAcademiques()
            ->where('annee_universitaire_id', $valide['annee_universitaire_id'])->exists()) {
            return redirect()->back()->withErrors([
                'annee' => 'Une inscription existe déjà pour cette année (historique conservé).',
            ]);
        }

        try {
            $nouvelle = $this->inscriptions->reinscrire(
                $inscription->etudiant,
                AnneeUniversitaire::findOrFail($valide['annee_universitaire_id']),
                (int) $request->user()->id,
                $valide,
            );
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['inscription' => $e->getMessage()]);
        }

        return redirect()->route('admin.etudiants.show', $inscription->etudiant)
            ->with('success', "Réinscription créée ({$nouvelle->anneeUniversitaire->libelle}).");
    }

    /**
     * Progression LMD : inscription N+1 depuis la décision officielle
     * publiée (dry-run côté service). Cibles explicites = admission
     * externe validée (ex. Master), jamais devinées.
     */
    public function progresser(Request $request, InscriptionAcademique $inscription): RedirectResponse
    {
        Gate::authorize('update', $inscription);

        $valide = $request->validate([
            'programme_id' => 'nullable|exists:programmes,id',
            'niveau_id' => 'nullable|exists:niveaux,id',
            'confirmer' => 'required|accepted',
        ]);

        abort_unless($request->user()->peutAccederFaculte((int) $inscription->etudiant->faculte_id), 404);

        // Isolation faculté sur la CIBLE explicite (audit 04/10/2026) —
        // une admission externe ne bascule jamais vers une autre faculté
        // que celle du demandeur (transfert = flux dédié).
        if ($valide['programme_id'] ?? null) {
            $programmeCible = Programme::findOrFail($valide['programme_id']);
            abort_unless($programmeCible->faculte_id === null
                || $request->user()->peutAccederFaculte((int) $programmeCible->faculte_id), 404);
        }

        try {
            $nouvelle = $this->progressions->appliquer(
                $inscription->etudiant,
                $inscription,
                (int) $request->user()->id,
                array_filter([
                    'programme_id' => $valide['programme_id'] ?? null,
                    'niveau_id' => $valide['niveau_id'] ?? null,
                ]),
            );
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['progression' => $e->getMessage()]);
        }

        return redirect()->route('admin.etudiants.show', $inscription->etudiant)
            ->with('success', "Progression créée : {$nouvelle->niveau} ({$nouvelle->anneeUniversitaire->libelle}).");
    }

    public function pedagogique(Request $request, InscriptionAcademique $inscription): View
    {
        Gate::authorize('view', $inscription);

        $inscription->loadMissing([
            'etudiant', 'anneeUniversitaire', 'programmeRef', 'pedagogiques.matiere', 'pedagogiques.ue',
        ]);

        $maquette = Maquette::with(['unitesEnseignement.ecues'])
            ->where('programme_id', $inscription->programme_id ?? $inscription->etudiant->programme_id)
            ->where('annee_universitaire_id', $inscription->annee_universitaire_id)
            ->where('statut', 'active')->first();

        return view('admin.inscriptions.pedagogique', [
            'inscription' => $inscription,
            'maquette' => $maquette,
        ]);
    }

    public function inscrireEcue(Request $request, InscriptionAcademique $inscription): RedirectResponse
    {
        Gate::authorize('update', $inscription);

        $matiere = Matiere::findOrFail($request->validate([
            'matiere_id' => 'required|exists:matieres,id',
        ])['matiere_id']);

        try {
            $this->inscriptions->inscrireEcue($inscription, $matiere, (int) $request->user()->id);
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['ecue' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', "ECUE {$matiere->code} inscrite (maquette vérifiée).");
    }

    /**
     * Inscriptions ECUE en masse (Phase 4 §12) : mêmes gardes,
     * rapport créés/existants/erreurs, succès partiel contrôlé.
     */
    public function inscrireEcues(Request $request, InscriptionAcademique $inscription): RedirectResponse
    {
        Gate::authorize('update', $inscription);

        $ids = $request->validate([
            'matiere_ids' => 'required|array|min:1|max:100',
            // LOOP saisies : le lot porte le même exists que l'unitaire —
            // pas de matière fantôme dans un rapport partiel.
            'matiere_ids.*' => 'integer|min:1|exists:matieres,id',
        ])['matiere_ids'];

        $rapport = $this->inscriptions->inscrireEcues($inscription, $ids, (int) $request->user()->id);

        if ($rapport['erreurs'] !== []) {
            return redirect()->back()->withErrors([
                'ecue' => "{$rapport['crees']} inscrite(s), {$rapport['existants']} déjà inscrite(s), "
                   .count($rapport['erreurs']).' erreur(s) : '.implode(' | ', array_slice($rapport['erreurs'], 0, 3)),
            ]);
        }

        return redirect()->back()->with('success', "{$rapport['crees']} ECUE(s) inscrite(s), {$rapport['existants']} déjà inscrite(s).");
    }

    public function retirerEcue(Request $request, InscriptionPedagogique $pedagogique): RedirectResponse
    {
        $pedagogique->loadMissing('inscription');
        Gate::authorize('update', $pedagogique->inscription);

        abort_unless($pedagogique->statut === 'inscrit', 422, 'ECUE déjà évaluée : retrait impossible.');

        $pedagogique->delete();

        return redirect()->back()->with('success', 'Inscription pédagogique retirée.');
    }

    /**
     * Bilan auto pour affichage immédiat (§19) : lit l'affectation active
     * + le dernier audit auto de CETTE inscription (pas d'oracle).
     */
    private function lireBilanAuto(InscriptionAcademique $inscription): string
    {
        $inscription->loadMissing(['etudiant', 'anneeUniversitaire']);

        $affectation = AffectationGroupe::where('etudiant_id', $inscription->etudiant_id)
            ->where('annee_universitaire_id', $inscription->annee_universitaire_id)
            ->where('statut', 'active')
            ->first();

        $audit = HistoriqueAcademique::where('etudiant_id', $inscription->etudiant_id)
            ->whereIn('action', [
                'affectation.auto_assignee', 'affectation.auto_non_assignee',
                'affectation.auto_existante', 'affectation.auto_verrouillee', 'affectation.auto_erreur',
            ])
            ->latest('id')
            ->first();

        $concerne = $audit !== null && str_contains((string) $audit->nouvelle_valeur, '"inscription_id":'.$inscription->id);

        if ($affectation !== null && $audit !== null && $concerne && $audit->action === 'affectation.auto_assignee') {
            return "Affectation automatique : ✓ Groupe attribué : {$affectation->groupe?->code}.";
        }

        if ($affectation !== null) {
            return "Affectation automatique : ✓ Déjà affecté ({$affectation->groupe?->code}, conservée).";
        }

        if ($audit !== null && $concerne) {
            $nouvelle = json_decode((string) $audit->nouvelle_valeur, true);

            $raison = is_array($nouvelle) ? (string) ($nouvelle['raison'] ?? $audit->action) : $audit->action;

            return "Affectation automatique : ⚠ Non affecté ({$raison}). Relance possible après correction.";
        }

        return 'Affectation automatique : ⚠ Non affecté (à traiter).';
    }

    /**
     * Attestation d'inscription (§33) : PDF via le moteur central, Gate
     * view + périmètre faculté (404 hors périmètre, anti-IDOR), audit
     * du téléchargement dans le service. Jamais de CIN complète.
     */
    public function attestation(Request $request, InscriptionAcademique $inscription, OfficialDocumentService $documents): RedirectResponse
    {
        Gate::authorize('view', $inscription);
        abort_unless($request->user()->peutAccederFaculte((int) $inscription->etudiant->faculte_id), 404);

        // Phase 16 §16.3 : émission via le registre (référence UNIQUE,
        // version, hash, vérifiable, révocable) — jamais de PDF volatil.
        try {
            $document = $documents->generer(
                $inscription->etudiant, 'attestation_inscription',
                (int) $inscription->id, (int) $request->user()->id,
            );
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['document' => $e->getMessage()]);
        }

        return redirect()->route('admin.documents-officiels.download', $document)
            ->with('success', "Attestation {$document->reference} générée.");
    }
}
