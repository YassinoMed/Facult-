<?php

namespace App\Modules\Evaluation\Controllers;

use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\EvaluationReconciliationEcart;
use App\Modules\Evaluation\Services\ExamContinuiteReconciliationService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * §2.15 — surface HTTP du dossier d'écart de réconciliation tri-sources.
 *
 * L'écran rend le rapprochement AUDITABLE : chaque divergence papier /
 * numérique devient un dossier persistant, instruit puis validé par deux
 * personnes distinctes. Aucun écart n'est supprimé et aucune transition ne
 * peut contourner le circuit (le serveur revérifie tout).
 */
class ExamReconciliationController
{
    public function __construct(private ExamContinuiteReconciliationService $service) {}

    public function index(Request $request): View
    {
        Gate::authorize('evaluations.view');

        $filtres = $request->validate([
            'epreuve_id' => ['nullable', 'integer', 'exists:evaluation_epreuves,id'],
            'statut' => ['nullable', Rule::in(EvaluationReconciliationEcart::STATUTS)],
        ]);

        $epreuveId = isset($filtres['epreuve_id']) ? (int) $filtres['epreuve_id'] : null;
        $statut = $filtres['statut'] ?? null;
        $faculteRestreinte = $request->user()->faculteRestreinteId();

        $epreuve = null;
        if ($epreuveId !== null) {
            $epreuve = Epreuve::with(['matiere', 'candidats.etudiant'])->findOrFail($epreuveId);

            // Périmètre facultaire : un identifiant d'URL hors faculté = 404,
            // jamais un simple filtre contournable.
            abort_unless(
                $epreuve->faculteId() === null
                    || $faculteRestreinte === null
                    || $request->user()->peutAccederFaculte($epreuve->faculteId()),
                404
            );
        }

        $epreuves = Epreuve::query()
            ->with('matiere')
            ->when($faculteRestreinte !== null, fn ($q) => $q->pourFaculteId($faculteRestreinte))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $ecarts = EvaluationReconciliationEcart::query()
            ->with(['epreuve.matiere', 'candidat.etudiant', 'instructeur:id,name', 'valideur:id,name'])
            ->when($faculteRestreinte !== null, fn ($q) => $q->whereHas(
                'epreuve',
                fn ($e) => $e->pourFaculteId($faculteRestreinte)
            ))
            ->when($epreuveId !== null, fn ($q) => $q->where('epreuve_id', $epreuveId))
            ->when($statut !== null, fn ($q) => $q->where('statut', $statut))
            ->orderByRaw("CASE statut WHEN 'ouvert' THEN 0 WHEN 'instruit' THEN 1 ELSE 2 END")
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $compteurs = EvaluationReconciliationEcart::query()
            ->when($faculteRestreinte !== null, fn ($q) => $q->whereHas(
                'epreuve',
                fn ($e) => $e->pourFaculteId($faculteRestreinte)
            ))
            ->selectRaw('statut, count(*) as total')
            ->groupBy('statut')
            ->pluck('total', 'statut');

        return view('modules.evaluation.reconciliation-index', [
            'epreuve' => $epreuve,
            'epreuves' => $epreuves,
            'ecarts' => $ecarts,
            'compteurs' => $compteurs,
            'filtres' => ['epreuve_id' => $epreuveId, 'statut' => $statut],
            'statuts' => EvaluationReconciliationEcart::LIBELLES_STATUTS,
        ]);
    }

    /**
     * Analyse papier → serveur : le rapprochement est calculé par le service
     * puis PERSISTÉ. Rejouer la même analyse ne duplique aucun dossier.
     */
    public function analyser(Request $request): RedirectResponse
    {
        Gate::authorize('evaluations.update');

        $donnees = $request->validate([
            'epreuve_id' => ['required', 'integer', 'exists:evaluation_epreuves,id'],
            'statut_papier' => ['required', 'array'],
            'statut_papier.*' => ['nullable', Rule::in(Candidat::STATUTS)],
            'signatures' => ['nullable', 'array'],
        ]);

        /** @var Epreuve $epreuve */
        $epreuve = Epreuve::with('matiere')->findOrFail($donnees['epreuve_id']);

        abort_unless(
            $epreuve->faculteId() === null
                || $request->user()->faculteRestreinteId() === null
                || $request->user()->peutAccederFaculte($epreuve->faculteId()),
            404
        );

        $candidats = Candidat::where('epreuve_id', $epreuve->id)->pluck('id');
        $signatures = (array) ($donnees['signatures'] ?? []);
        $pointages = [];

        foreach ($candidats as $candidatId) {
            $statutPapier = $donnees['statut_papier'][$candidatId] ?? null;

            if ($statutPapier === null || $statutPapier === '') {
                continue;
            }

            $pointages[] = [
                'candidat_id' => (int) $candidatId,
                'statut_papier' => (string) $statutPapier,
                'signature_presente' => array_key_exists($candidatId, $signatures),
            ];
        }

        if ($pointages === []) {
            return back()->withErrors([
                'statut_papier' => 'Renseignez au moins un pointage papier avant de lancer l’analyse.',
            ])->withInput();
        }

        $resultat = $this->service->rapprocherTriSources([
            'epreuve_id' => $epreuve->id,
            'pointages_papier' => $pointages,
        ]);

        $persistance = $this->service->persisterEcarts($resultat, (int) $request->user()->id);

        return redirect()
            ->route('admin.evaluations.reconciliation.index', ['epreuve_id' => $epreuve->id])
            ->with('succes', sprintf(
                'Analyse terminée : %d divergence(s) détectée(s), %d nouveau(x) dossier(s), %d déjà consigné(s).',
                $resultat['divergences_detectees'],
                $persistance['crees'],
                $persistance['existants']
            ));
    }

    public function show(EvaluationReconciliationEcart $ecart): View
    {
        $this->perimetre($ecart);
        Gate::authorize('evaluations.view');

        $ecart->load([
            'epreuve.matiere',
            'candidat.etudiant',
            'detecteur:id,name',
            'instructeur:id,name',
            'valideur:id,name',
        ]);

        return view('modules.evaluation.reconciliation-show', [
            'ecart' => $ecart,
            'statutsCandidat' => Candidat::STATUTS,
        ]);
    }

    public function instruire(Request $request, EvaluationReconciliationEcart $ecart): RedirectResponse
    {
        $this->perimetre($ecart);
        Gate::authorize('evaluations.update');

        $donnees = $request->validate([
            'statut_retenu' => ['required', Rule::in(Candidat::STATUTS)],
            'motif_instruction' => ['required', 'string', 'min:5', 'max:2000'],
            'preuve_reference' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->service->instruireEcart(
                $ecart,
                (int) $request->user()->id,
                $donnees['statut_retenu'],
                $donnees['motif_instruction'],
                $donnees['preuve_reference'] ?? null
            );
        } catch (DomainException $e) {
            return back()->withErrors(['reconciliation' => $e->getMessage()])->withInput();
        }

        return redirect()
            ->route('admin.evaluations.reconciliation.show', $ecart)
            ->with('succes', 'Écart instruit : la validation par une personne distincte reste obligatoire.');
    }

    public function valider(Request $request, EvaluationReconciliationEcart $ecart): RedirectResponse
    {
        $this->perimetre($ecart);
        Gate::authorize('evaluations.update');

        $donnees = $request->validate([
            'commentaire_validation' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->service->validerEcart(
                $ecart,
                (int) $request->user()->id,
                $donnees['commentaire_validation'] ?? null
            );
        } catch (DomainException $e) {
            return back()->withErrors(['reconciliation' => $e->getMessage()])->withInput();
        }

        return redirect()
            ->route('admin.evaluations.reconciliation.show', $ecart)
            ->with('succes', 'Écart validé : le statut retenu est appliqué et le dossier est scellé.');
    }

    /** Périmètre facultaire calculé côté serveur depuis l'épreuve de l'écart. */
    private function perimetre(EvaluationReconciliationEcart $ecart): void
    {
        $ecart->loadMissing('epreuve');

        $faculteId = $ecart->epreuve?->faculteId();
        $utilisateur = request()->user();

        abort_if($ecart->epreuve === null, 404);
        abort_unless(
            $faculteId === null
                || $utilisateur === null
                || $utilisateur->faculteRestreinteId() === null
                || $utilisateur->peutAccederFaculte($faculteId),
            404
        );
    }
}
