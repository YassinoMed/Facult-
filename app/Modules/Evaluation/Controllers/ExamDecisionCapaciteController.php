<?php

namespace App\Modules\Evaluation\Controllers;

use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Services\ExamDecisionCapacitePermutationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Validator;
use Inertia\Response;

/**
 * Contrôleur de la décision D-04 V2 — capacité utile et permutations de salles.
 *
 * Toutes les actions sont permission-gées (`epreuves.view` en lecture,
 * `epreuves.update` en écriture) et limitées par throttle. La validation de
 * capacité et d'absence de conflit est faite intégralement côté serveur par
 * `ExamDecisionCapacitePermutationService`.
 */
class ExamDecisionCapaciteController
{
    public function __construct(
        private ExamDecisionCapacitePermutationService $decision,
    ) {}

    /* ---------------------------------------------------------------------
     | Lectures
     * ------------------------------------------------------------------- */

    public function analyser(Request $request): RedirectResponse|Response|JsonResponse
    {
        Gate::authorize('epreuves.view');

        $donnees = $this->validerRequeteLecture($request);

        $diagnostic = $this->decision->diagnostic(
            $donnees['annee_id'],
            $donnees['faculte_id'],
            $donnees['session_id'],
            $donnees['examen_id'],
            $donnees['epreuve_id'],
        );

        return $this->repondre($request, $diagnostic, 'analyse_terminee');
    }

    /**
     * Diagnostic en lecture seule d'une salle à capacité non confirmée ou insuffisante.
     * Propose des salles candidates compatibles sans modifier le planning.
     */
    public function diagnostiquerSalle(Request $request): JsonResponse
    {
        Gate::authorize('epreuves.view');

        $donnees = Validator::make($request->all(), [
            'epreuve_id' => 'required|integer|exists:evaluation_epreuves,id',
            'empreinte' => 'nullable|string',
        ])->validate();

        $epreuve = Epreuve::where('id', $donnees['epreuve_id'])->where('statut', '!=', 'cancelled')->first();
        if ($epreuve === null) {
            return response()->json([
                'succes' => false,
                'message' => 'Épreuve introuvable ou annulée.',
            ], 404);
        }

        try {
            $diagnostic = $this->decision->diagnostiquerCapaciteSalle($epreuve, $request->user());
        } catch (\DomainException $e) {
            return response()->json([
                'succes' => false,
                'message' => $e->getMessage(),
            ], 403);
        }

        if (! empty($donnees['empreinte'])) {
            $diagnostic['est_obsolete'] = ($diagnostic['empreinte'] !== $donnees['empreinte']);
        }

        return response()->json([
            'succes' => true,
            'diagnostic' => $diagnostic,
        ]);
    }

    public function generer(Request $request): RedirectResponse|Response|JsonResponse
    {
        Gate::authorize('epreuves.view');

        $donnees = Validator::make($request->all(), [
            'epreuve_id' => 'required|integer|exists:evaluation_epreuves,id',
            'type' => 'nullable|string',
            'salle_ids' => 'nullable|array',
            'salle_ids.*' => 'nullable|integer|exists:rooms,id',
            'epreuve_partenaire_id' => 'nullable|integer|exists:evaluation_epreuves,id',
            'salle_partenaire_ids' => 'nullable|array',
            'salle_partenaire_ids.*' => 'nullable|integer|exists:rooms,id',
            'motif' => 'nullable|string|max:1000',
            'variante_cle' => 'nullable|string',
            'utilisateur_id' => 'nullable|integer|exists:users,id',
        ])->validate();

        $epreuve = Epreuve::where('id', $donnees['epreuve_id'])->where('statut', '!=', 'cancelled')->first();
        if ($epreuve === null) {
            return $this->erreurJson($request, 'Épreuve introuvable ou annulée.', 422);
        }

        try {
            $proposition = $this->decision->proposer(
                $epreuve,
                [
                    'type' => $donnees['type'] ?? 'combinaison',
                    'salle_ids' => $donnees['salle_ids'] ?? [],
                    'epreuve_partenaire_id' => $donnees['epreuve_partenaire_id'] ?? null,
                    'salle_partenaire_ids' => $donnees['salle_partenaire_ids'] ?? [],
                    'motif' => $donnees['motif'] ?? null,
                    'variante_cle' => $donnees['variante_cle'] ?? null,
                ],
                $donnees['utilisateur_id'] ?? null,
            );
        } catch (\DomainException $e) {
            return $this->erreurJson($request, $e->getMessage(), 422);
        }

        return $this->repondre($request, $proposition, 'proposition_generee');
    }

    public function previsualiser(Request $request): RedirectResponse|Response|JsonResponse
    {
        Gate::authorize('epreuves.view');

        $donnees = Validator::make($request->all(), [
            'proposition' => 'required|array',
            'utilisateur_id' => 'nullable|integer|exists:users,id',
        ])->validate();

        // La prévisualisation est une lecture pure : on vérifie que la
        // proposition correspond encore au contexte courant (empreinte).
        $epreuveId = (int) ($donnees['proposition']['epreuve_id'] ?? 0);
        $epreuve = Epreuve::find($epreuveId);
        if ($epreuve === null) {
            return $this->erreurJson($request, 'Épreuve introuvable.', 422);
        }

        $diagnostic = $this->decision->diagnostic(
            $epreuve->annee_universitaire_id,
            $epreuve->faculteId(),
            null,
            $epreuve->examen_id,
            $epreuveId,
        );

        $empreinteProposition = $donnees['proposition']['empreinte'] ?? null;
        $obsolete = $empreinteProposition !== null && $empreinteProposition !== ($diagnostic['empreinte'] ?? null);

        return $this->repondre($request, [
            'epreuve_id' => $epreuveId,
            'obsolete' => $obsolete,
            'empreinte_courante' => $diagnostic['empreinte'] ?? null,
            'kpis' => $diagnostic['kpis'] ?? [],
            'conflits' => $diagnostic['conflits'] ?? [],
        ], 'previsualisation_terminee');
    }

    public function comparer(Request $request): RedirectResponse|Response|JsonResponse
    {
        Gate::authorize('epreuves.view');

        $donnees = Validator::make($request->all(), [
            'a' => 'required_without:epreuve_id|array',
            'b' => 'required_without:epreuve_id|array',
            'epreuve_id' => 'required_without:a|integer',
            'annee_id' => 'required_with:epreuve_id|integer',
            'faculte_id' => 'nullable|integer',
            'session_id' => 'nullable|integer',
            'variante_cles' => 'nullable|array',
            'variante_cles.*' => 'string',
        ])->validate();

        if (! empty($donnees['epreuve_id'])) {
            $diagnostic = $this->decision->diagnostic(
                (int) $donnees['annee_id'],
                isset($donnees['faculte_id']) ? (int) $donnees['faculte_id'] : null,
                isset($donnees['session_id']) ? (int) $donnees['session_id'] : null,
                null,
                (int) $donnees['epreuve_id'],
            );

            $ligne = $diagnostic['lignes'][(int) $donnees['epreuve_id']] ?? null;
            $variantes = is_array($ligne) ? ($ligne['variantes'] ?? []) : [];

            if (! empty($donnees['variante_cles'])) {
                $cles = array_map('strval', $donnees['variante_cles']);
                $variantes = array_values(array_filter(
                    $variantes,
                    fn (array $variante) => in_array((string) ($variante['cle'] ?? ''), $cles, true)
                ));
            }

            $comparaison = $this->decision->comparerVariantes($variantes);
            $comparaison['epreuve_id'] = (int) $donnees['epreuve_id'];

            return $this->repondre($request, $comparaison, 'comparaison_terminee');
        }

        $comparaison = $this->comparerLocalement($donnees['a'], $donnees['b']);

        return $this->repondre($request, $comparaison, 'comparaison_terminee');
    }

    public function conflits(Request $request): RedirectResponse|Response|JsonResponse
    {
        Gate::authorize('epreuves.view');

        $donnees = $this->validerRequeteLecture($request);

        $diagnostic = $this->decision->diagnostic(
            $donnees['annee_id'],
            $donnees['faculte_id'],
            $donnees['session_id'],
            $donnees['examen_id'],
            $donnees['epreuve_id'],
        );

        return $this->repondre($request, ['conflits' => $diagnostic['conflits'] ?? []], 'conflits_recuperez');
    }

    public function historique(Request $request): RedirectResponse|Response|JsonResponse
    {
        Gate::authorize('epreuves.view');

        $donnees = $this->validerRequeteLecture($request);

        $liste = $this->decision->historique(
            $donnees['annee_id'],
            $donnees['faculte_id'],
            $donnees['examen_id'],
            $donnees['epreuve_id'],
            (int) ($request->input('limite', 15)),
        );

        return $this->repondre($request, ['historique' => $liste], 'historique_recupere');
    }

    public function propositionsRecentes(Request $request): RedirectResponse|Response|JsonResponse
    {
        Gate::authorize('epreuves.view');

        $donnees = $this->validerRequeteLecture($request);

        $payload = $this->decision->payloadVue(
            $donnees['annee_id'],
            $donnees['faculte_id'],
            $donnees['session_id'],
            $donnees['examen_id'],
            $donnees['epreuve_id'],
            $request->user()?->id,
        );

        return $this->repondre($request, [
            'propositions_recentes' => $payload['propositions_recentes'] ?? [],
            'empreinte' => $payload['empreinte'] ?? null,
        ], 'propositions_recentes_recuperees');
    }

    /* ---------------------------------------------------------------------
     | Décisions (écritures)
     * ------------------------------------------------------------------- */

    public function accepter(Request $request): RedirectResponse
    {
        Gate::authorize('epreuves.update');

        $donnees = Validator::make($request->all(), [
            'epreuve_id' => 'required|integer|exists:evaluation_epreuves,id',
            'proposition_uuid' => 'nullable|string',
            'type' => 'nullable|string',
            'salle_ids' => 'nullable|array',
            'salle_ids.*' => 'nullable|integer|exists:rooms,id',
            'epreuve_partenaire_id' => 'nullable|integer|exists:evaluation_epreuves,id',
            'salle_partenaire_ids' => 'nullable|array',
            'salle_partenaire_ids.*' => 'nullable|integer|exists:rooms,id',
            'motif' => 'required|string|min:5|max:1000',
            'empreinte' => 'nullable|string',
            'utilisateur_id' => 'nullable|integer|exists:users,id',
        ])->validate();

        $epreuve = Epreuve::where('id', $donnees['epreuve_id'])->where('statut', '!=', 'cancelled')->first();
        if ($epreuve === null) {
            return Redirect::back()->withInput()->withErrors(['capacite' => 'Épreuve introuvable.'])->with('error', 'Épreuve introuvable.')->anchor('capacite');
        }

        try {
            $resultat = $this->decision->appliquer(
                $epreuve,
                [
                    'proposition_uuid' => $donnees['proposition_uuid'] ?? null,
                    'type' => $donnees['type'] ?? null,
                    'salle_ids' => $donnees['salle_ids'] ?? [],
                    'epreuve_partenaire_id' => $donnees['epreuve_partenaire_id'] ?? null,
                    'salle_partenaire_ids' => $donnees['salle_partenaire_ids'] ?? [],
                    'motif' => $donnees['motif'],
                    'empreinte' => $donnees['empreinte'] ?? null,
                ],
                $donnees['utilisateur_id'] ?? $request->user()?->id,
            );
        } catch (\DomainException $e) {
            return Redirect::back()->withInput()->withErrors(['capacite' => $e->getMessage()])->with('error', $e->getMessage())->anchor('capacite');
        }

        return Redirect::back()
            ->with('success', 'Décision de capacité appliquée à l’épreuve #'.$resultat['epreuve_id'].'.')
            ->anchor('capacite');
    }

    public function refuser(Request $request): RedirectResponse
    {
        Gate::authorize('epreuves.update');

        $donnees = Validator::make($request->all(), [
            'epreuve_id' => 'required|integer|exists:evaluation_epreuves,id',
            'proposition_uuid' => 'nullable|string',
            'type' => 'nullable|string',
            'salle_ids' => 'nullable|array',
            'salle_ids.*' => 'nullable|integer|exists:rooms,id',
            'motif' => 'required|string|min:5|max:1000',
            'utilisateur_id' => 'nullable|integer|exists:users,id',
        ])->validate();

        $epreuve = Epreuve::where('id', $donnees['epreuve_id'])->where('statut', '!=', 'cancelled')->first();
        if ($epreuve === null) {
            return Redirect::back()->withInput()->withErrors(['capacite' => 'Épreuve introuvable.'])->with('error', 'Épreuve introuvable.')->anchor('capacite');
        }

        try {
            $resultat = $this->decision->refuser(
                $epreuve,
                [
                    'proposition_uuid' => $donnees['proposition_uuid'] ?? null,
                    'type' => $donnees['type'] ?? null,
                    'salle_ids' => $donnees['salle_ids'] ?? [],
                    'motif' => $donnees['motif'],
                ],
                $donnees['utilisateur_id'] ?? $request->user()?->id,
            );
        } catch (\DomainException $e) {
            return Redirect::back()->withInput()->withErrors(['capacite' => $e->getMessage()])->with('error', $e->getMessage())->anchor('capacite');
        }

        return Redirect::back()
            ->with('success', 'Proposition refusée et tracée pour l’épreuve #'.$resultat['epreuve_id'].'.')
            ->anchor('capacite');
    }

    public function annuler(Request $request): RedirectResponse
    {
        Gate::authorize('epreuves.update');

        $donnees = Validator::make($request->all(), [
            'audit_id' => 'required|integer|exists:audit_versioning,id',
            'motif' => 'required|string|min:5|max:1000',
            'utilisateur_id' => 'nullable|integer|exists:users,id',
        ])->validate();

        try {
            $resultat = $this->decision->annulerDecision(
                $donnees['audit_id'],
                $donnees['utilisateur_id'] ?? $request->user()?->id,
                $donnees['motif'],
            );
        } catch (\DomainException $e) {
            return Redirect::back()->withInput()->withErrors(['capacite' => $e->getMessage()])->with('error', $e->getMessage())->anchor('capacite');
        }

        return Redirect::back()
            ->with('success', 'Décision D-04 #'.$resultat['audit_id'].' annulée et état restauré.')
            ->anchor('capacite');
    }

    /* ---------------------------------------------------------------------
     | Payload de vue pour l'insertion dans groupes()
     * ------------------------------------------------------------------- */

    public function payloadVue(Request $request): array
    {
        Gate::authorize('epreuves.view');

        $donnees = $this->validerRequeteLecture($request);

        return $this->decision->payloadVue(
            $donnees['annee_id'],
            $donnees['faculte_id'],
            $donnees['session_id'],
            $donnees['examen_id'],
            $donnees['epreuve_id'],
            $request->user()?->id,
        );
    }

    /* ---------------------------------------------------------------------
     | Helpers privés
     * ------------------------------------------------------------------- */

    /**
     * @return array{annee_id: ?int, faculte_id: ?int, session_id: ?int, examen_id: ?int, epreuve_id: ?int}
     */
    private function validerRequeteLecture(Request $request): array
    {
        $valide = Validator::make($request->all(), [
            'annee_id' => 'nullable|integer|exists:annees_universitaires,id',
            'faculte_id' => 'nullable|integer',
            'session_id' => 'nullable|integer|exists:evaluation_sessions,id',
            'examen_id' => 'nullable|integer|exists:evaluation_examens,id',
            'epreuve_id' => 'nullable|integer|exists:evaluation_epreuves,id',
        ])->validate();

        return [
            'annee_id' => $valide['annee_id'] ?? null,
            'faculte_id' => $valide['faculte_id'] ?? null,
            'session_id' => $valide['session_id'] ?? null,
            'examen_id' => $valide['examen_id'] ?? null,
            'epreuve_id' => $valide['epreuve_id'] ?? null,
        ];
    }

    /**
     * Comparaison locale de deux propositions (lecture seule, aucune écriture).
     */
    private function comparerLocalement(array $a, array $b): array
    {
        $criteria = ['type', 'capacite_utile_totale', 'effectif_eligible', 'salle_ids', 'salle_partenaire_ids'];

        $diff = [];
        foreach ($criteria as $c) {
            $va = $a[$c] ?? null;
            $vb = $b[$c] ?? null;
            if ((array) $va !== (array) $vb) {
                $diff[$c] = ['a' => $va, 'b' => $vb];
            }
        }

        return [
            'a_resume' => $this->resumeProposition($a),
            'b_resume' => $this->resumeProposition($b),
            'differences' => $diff,
        ];
    }

    private function resumeProposition(array $proposition): array
    {
        return [
            'uuid' => $proposition['uuid'] ?? null,
            'type' => $proposition['type'] ?? '—',
            'epreuve' => $proposition['epreuve_id'] ?? '—',
            'salles' => $proposition['salle_ids'] ?? [],
            'capacite_utile' => $proposition['capacite_utile_totale'] ?? 0,
            'effectif' => $proposition['effectif_eligible'] ?? 0,
        ];
    }

    private function repondre(Request $request, array $donnees, string $evenement): RedirectResponse|Response|JsonResponse
    {
        if ($request->wantsJson() || $request->header('Accept') === 'application/json') {
            return response()->json(array_merge(['evenement' => $evenement], $donnees));
        }

        return Redirect::back()
            ->with('success', ucfirst($evenement).'.')
            ->with('capacite_payload', $donnees)
            ->anchor('capacite');
    }

    private function erreurJson(Request $request, string $message, int $status): RedirectResponse|Response|JsonResponse
    {
        if ($request->wantsJson() || $request->header('Accept') === 'application/json') {
            return response()->json(['erreur' => $message], $status);
        }

        return Redirect::back()
            ->withInput()
            ->withErrors(['capacite' => $message])
            ->with('error', $message)
            ->anchor('capacite');
    }
}
