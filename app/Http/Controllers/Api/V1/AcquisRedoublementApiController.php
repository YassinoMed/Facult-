<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ExecuterCampagneRedoublementJob;
use App\Models\CampagneRedoublement;
use App\Models\Etudiant;
use App\Modules\ParcoursAcademique\Models\ParcoursPlan;
use App\Modules\Schedule\Models\Group;
use App\Services\Acquis\CampagneRedoublementMasseService;
use App\Services\Acquis\MoteurDecisionAcademiqueService;
use App\Services\Acquis\PlanRedoublementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

/**
 * LOOP 32 — API REST v1 sécurisée pour la gestion des acquis et du redoublement.
 * Protégée par Sanctum, périmètre d'autorisation anti-BOLA et rate limiting.
 */
class AcquisRedoublementApiController extends Controller
{
    public function __construct(
        protected MoteurDecisionAcademiqueService $moteurDecision,
        protected PlanRedoublementService $planRedoublementService,
        protected CampagneRedoublementMasseService $campagneMasseService
    ) {}

    /**
     * Synthèse complète des acquis pédagogiques d'un étudiant.
     */
    public function synthese(Request $request, Etudiant $student): JsonResponse
    {
        $this->verifierPerimetreFaculte($student);

        $anneeId = $request->query('annee_universitaire_id') ? (int) $request->query('annee_universitaire_id') : null;
        $synthese = $this->moteurDecision->determinerDecision($student, $anneeId);

        return response()->json([
            'succes' => true,
            'donnees' => [
                'etudiant' => [
                    'id' => $student->id,
                    'matricule' => $student->num_inscription,
                    'nom' => $student->nom,
                    'prenom' => $student->prenom,
                    'statut' => $student->statut,
                ],
                'decision_globale' => $synthese['decision_globale'] ?? 'NON_DETERMINEE',
                'credits_capitalises' => $synthese['credits_capitalises'] ?? 0,
                'credits_non_acquis' => $synthese['credits_non_acquis'] ?? 0,
                'unites_statuts' => $synthese['unites_statuts'] ?? [],
                'elements_a_reprendre' => $synthese['elements_a_reprendre'] ?? [],
                'elements_conserves' => $synthese['elements_conserves'] ?? [],
                'anomalies' => $synthese['anomalies'] ?? [],
            ],
            'horodatage' => now()->toIso8601String(),
        ]);
    }

    /**
     * Consultation du plan de redoublement officiel d'un étudiant.
     */
    public function plan(Request $request, Etudiant $student): JsonResponse
    {
        $this->verifierPerimetreFaculte($student);

        $plan = ParcoursPlan::where('etudiant_id', $student->id)
            ->latest('id')
            ->first();

        if ($plan === null) {
            return response()->json([
                'succes' => false,
                'message' => 'Aucun plan de réinscription ciblée n\'est actuellement enregistré pour cet étudiant.',
            ], 404);
        }

        $contenu = is_array($plan->contenu) ? $plan->contenu : [];

        return response()->json([
            'succes' => true,
            'donnees' => [
                'plan_id' => $plan->id,
                'statut' => $plan->statut,
                'objectif' => $plan->objectif,
                'version' => $plan->version,
                'credits_acquis_conserves' => $contenu['credits_acquis_conserves'] ?? 0,
                'credits_restants' => $contenu['credits_restants'] ?? 0,
                'elements_a_reprendre' => $contenu['elements_a_reprendre'] ?? [],
                'elements_conserves' => $contenu['elements_conserves'] ?? [],
                'prerequis_bloquants' => $contenu['prerequis_bloquants'] ?? [],
                'anomalies' => $contenu['anomalies'] ?? [],
                'auteur_id' => $plan->auteur_id,
            ],
            'horodatage' => now()->toIso8601String(),
        ]);
    }

    /**
     * Simulation dynamique du parcours de réinscription pour un étudiant.
     */
    public function simulation(Request $request, Etudiant $student): JsonResponse
    {
        $this->verifierPerimetreFaculte($student);

        $simulation = $this->planRedoublementService->simulerPlan($student);

        return response()->json([
            'succes' => true,
            'donnees' => [
                'credits_acquis_conserves' => $simulation['credits_acquis_conserves'] ?? 0,
                'credits_restants' => $simulation['credits_restants'] ?? 0,
                'elements_a_reprendre' => $simulation['elements_a_reprendre'] ?? [],
                'elements_conserves' => $simulation['elements_conserves'] ?? [],
                'prerequis_bloquants' => $simulation['prerequis_bloquants'] ?? [],
                'anomalies' => $simulation['anomalies'] ?? [],
                'est_validable' => $simulation['est_validable'] ?? false,
            ],
            'horodatage' => now()->toIso8601String(),
        ]);
    }

    /**
     * Déclenchement d'une campagne de redoublement de masse par cohorte (sécurisé, rate-limited).
     *
     * Sécurité : le scope Sanctum `archives:write` ne suffit pas. La permission
     * `acquis.campagne.lancer` est exigée (séparation lecture / exécution de masse),
     * puis le périmètre faculté du token est appliqué à la cohorte ET au groupe.
     */
    public function lancerCampagne(Request $request): JsonResponse
    {
        Gate::authorize('acquis.campagne.lancer');

        $donnees = $request->validate([
            'annee_universitaire_id' => ['required', 'integer', 'min:1'],
            // `programme_id` est le nom canonique ; `formation_id` reste accepté
            // comme alias historique (A5). Les deux sont refusés s'ils divergent.
            'programme_id' => ['nullable', 'integer', 'min:1'],
            'formation_id' => ['nullable', 'integer', 'min:1'],
            'groupe_id' => ['nullable', 'integer', 'min:1'],
            'dry_run' => ['nullable', 'boolean'],
            'ignorer_alertes_mineures' => ['nullable', 'boolean'],
        ]);

        if (isset($donnees['programme_id'], $donnees['formation_id'])
            && (int) $donnees['programme_id'] !== (int) $donnees['formation_id']) {
            abort(422, 'programme_id et formation_id (alias) ne peuvent pas désigner deux programmes différents.');
        }

        $anneeUniversitaireId = (int) $donnees['annee_universitaire_id'];
        $programmeId = isset($donnees['programme_id'])
            ? (int) $donnees['programme_id']
            : (isset($donnees['formation_id']) ? (int) $donnees['formation_id'] : null);
        $groupeId = isset($donnees['groupe_id']) ? (int) $donnees['groupe_id'] : null;
        $dryRun = (bool) ($donnees['dry_run'] ?? true);
        $ignorerAlertes = (bool) ($donnees['ignorer_alertes_mineures'] ?? false);

        // 0. Isolation facultaire stricte (anti-BOLA) : un groupe hors périmètre
        //    est indistinguable d'un groupe inexistant (404 identique).
        $faculteRestreinteId = $request->user()?->faculteRestreinteId();

        if ($groupeId !== null) {
            $groupe = Group::query()->find($groupeId);

            abort_if(
                $groupe === null
                || ($faculteRestreinteId !== null && (int) $groupe->faculte_id !== $faculteRestreinteId),
                404,
                'Ressource introuvable dans votre périmètre.'
            );
        }

        // 1. Critères de cohorte — le groupe est un filtre réel (A1), le
        //    périmètre faculté du token est imposé côté serveur.
        $criteres = array_filter([
            'annee_id' => $anneeUniversitaireId,
            'programme_id' => $programmeId,
            'groupe_id' => $groupeId,
            'faculte_id' => $faculteRestreinteId,
        ], static fn ($valeur) => $valeur !== null);

        // 2. Campagne persistée (A3) : référence stable, critères sans PII.
        $acteurId = (int) ($request->user()?->id ?? 1);

        // 3. Mode simulation : préparation synchrone, mais tracée par une campagne.
        if ($dryRun) {
            $campagne = $this->campagneMasseService->creerCampagne(
                $criteres,
                CampagneRedoublement::MODE_SIMULATION,
                $acteurId,
                $ignorerAlertes
            );

            $preparation = $this->campagneMasseService->preparerCampagne($criteres, $campagne->reference);

            $campagne->update([
                'statut' => CampagneRedoublement::STATUT_TERMINEE,
                'dossiers_total' => (int) ($preparation['total_etudiants'] ?? 0),
                'bilan' => Arr::except($preparation, ['details_etudiants']),
                'demarree_a' => now(),
                'terminee_a' => now(),
            ]);

            return response()->json([
                'succes' => true,
                'mode' => 'simulation_preparatoire',
                'campagne' => $this->resumeCampagne($campagne->fresh()),
                'donnees' => $preparation,
                'horodatage' => now()->toIso8601String(),
            ]);
        }

        // 3. Exécution effective : déléguée à un job (A4). La requête rend la
        //    main immédiatement (202) et le suivi se fait par référence.
        $campagne = $this->campagneMasseService->creerCampagne(
            $criteres,
            CampagneRedoublement::MODE_EXECUTION,
            $acteurId,
            $ignorerAlertes
        );

        ExecuterCampagneRedoublementJob::dispatch($campagne->id);

        return response()->json([
            'succes' => true,
            'mode' => 'execution_reelle',
            'campagne' => $this->resumeCampagne($campagne),
            'suivi' => route('api.v1.acquis.campagnes.suivi', ['reference' => $campagne->reference]),
            'horodatage' => now()->toIso8601String(),
        ], 202);
    }

    /**
     * Suivi d'une campagne persistée (A3/A4).
     *
     * Anti-BOLA : une campagne hors du périmètre faculté du token est
     * indistinguable d'une référence inexistante (404 identique).
     */
    public function suiviCampagne(Request $request, string $reference): JsonResponse
    {
        $campagne = CampagneRedoublement::query()->where('reference', $reference)->first();
        $faculteRestreinteId = $request->user()?->faculteRestreinteId();

        abort_if(
            $campagne === null
            || ($faculteRestreinteId !== null && (int) $campagne->faculte_id !== $faculteRestreinteId),
            404,
            'Ressource introuvable dans votre périmètre.'
        );

        return response()->json([
            'succes' => true,
            'donnees' => $this->resumeCampagne($campagne),
            'horodatage' => now()->toIso8601String(),
        ]);
    }

    /**
     * Vue sérialisée d'une campagne — agrégats uniquement, jamais de nominatif.
     *
     * @return array<string, mixed>
     */
    protected function resumeCampagne(CampagneRedoublement $campagne): array
    {
        return [
            'reference' => $campagne->reference,
            'statut' => $campagne->statut,
            'mode' => $campagne->mode,
            'annee_universitaire_id' => $campagne->annee_universitaire_id,
            'programme_id' => $campagne->programme_id,
            'groupe_id' => $campagne->groupe_id,
            'dossiers_total' => $campagne->dossiers_total,
            'dossiers_traites' => $campagne->dossiers_traites,
            'bilan' => $campagne->bilan,
            'erreur' => $campagne->erreur,
            'creee_a' => $campagne->created_at?->toIso8601String(),
            'demarree_a' => $campagne->demarree_a?->toIso8601String(),
            'terminee_a' => $campagne->terminee_a?->toIso8601String(),
        ];
    }

    /**
     * Anti-BOLA (§17) : Vérifie que l'étudiant ciblé relève du périmètre faculté de l'utilisateur.
     */
    protected function verifierPerimetreFaculte(Etudiant $student): void
    {
        $faculteRestreinteId = request()->user()?->faculteRestreinteId();

        if ($faculteRestreinteId !== null && (int) $student->faculte_id !== $faculteRestreinteId) {
            abort(404, 'Ressource introuvable dans votre périmètre.');
        }
    }
}
