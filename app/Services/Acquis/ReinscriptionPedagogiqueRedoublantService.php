<?php

namespace App\Services\Acquis;

use App\Models\Etudiant;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Models\InscriptionPedagogique;
use App\Models\User;
use App\Modules\Referentiel\Models\UniteEnseignement;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Service de réinscription pédagogique sélective du redoublant (LOOP 07).
 *
 * Applique le principe cardinal de l'Article 10 de l'Arrêté du 7 juillet 2023 :
 * - Les unités et éléments dont les crédits sont capitalisés sont valorisés et
 *   ne sont PAS repassés pendant l'année de redoublement.
 * - L'étudiant est inscrit UNIQUEMENT aux enseignements restant réellement à valider.
 * - Zéro duplication de crédits et traçabilité intégrale.
 */
class ReinscriptionPedagogiqueRedoublantService
{
    public function __construct(
        private readonly CalculAcquisPedagogiquesService $calculAcquisService,
        private readonly PlanRedoublementService $planRedoublementService,
    ) {}

    /**
     * Exécute la réinscription pédagogique sélective d'un redoublant.
     *
     * @return array{
     *     inscription_academique_id: int,
     *     etudiant_id: int,
     *     elements_reinscrits_a_reprendre: array<int, array>,
     *     elements_conserves_non_reinscrits: array<int, array>,
     *     total_credits_a_reprendre: int,
     *     total_credits_conserves: int,
     *     inscriptions_pedagogiques_creees: int
     * }
     */
    public function reinscrireRedoublant(
        Etudiant $etudiant,
        InscriptionAcademique $nouvelleInscription,
        ?int $agentId = null
    ): array {
        if ((int) $nouvelleInscription->etudiant_id !== (int) $etudiant->id) {
            throw new DomainException('Incohérence : l’inscription académique cible n’appartient pas à cet étudiant.');
        }

        $userValide = $agentId !== null && User::where('id', $agentId)->exists();
        $agentIdFinal = $userValide ? $agentId : null;

        // Récupérer l'inscription antérieure pour évaluer les acquis
        $ancienneInscription = InscriptionAcademique::where('etudiant_id', $etudiant->id)
            ->where('id', '!=', $nouvelleInscription->id)
            ->where('annee_universitaire_id', '<', $nouvelleInscription->annee_universitaire_id)
            ->latest('annee_universitaire_id')
            ->first();

        // Analyse complète des acquis cumulés
        $analyse = $this->calculAcquisService->analyserAcquis($etudiant, null);

        return DB::transaction(function () use ($etudiant, $nouvelleInscription, $analyse, $agentIdFinal) {
            $idsEcuesConserves = collect($analyse['elements_conserves'])
                ->filter(fn ($el) => ($el['type'] ?? '') === 'ECUE')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $idsUesConservees = collect($analyse['elements_conserves'])
                ->filter(fn ($el) => in_array($el['type'] ?? '', ['UE', 'UE_GLOBALE'], true))
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $elementsReinscrits = [];
            $elementsConserves = [];
            $inscriptionsCreees = 0;
            $creditsAReprendre = 0;
            $creditsConserves = $analyse['total_credits_acquis'];

            // Parcourir toutes les UEs de l'année/semestres du redoublement
            $programme = $nouvelleInscription->programmeRef ?? $etudiant->programmeRef;
            $ues = UniteEnseignement::where('programme_id', $programme?->id)
                ->where('actif', true)
                ->with(['ecues' => fn ($q) => $q->where('actif', true)])
                ->get();

            foreach ($ues as $ue) {
                $ueEstConservee = in_array((int) $ue->id, $idsUesConservees, true);

                if ($ueEstConservee) {
                    // L'UE entière est capitalisée ou compensée : elle n'est pas réinscrite pour examen
                    $elementsConserves[] = [
                        'type' => 'UE',
                        'id' => $ue->id,
                        'code' => $ue->code,
                        'nom' => $ue->nom,
                        'credits' => (int) $ue->credits,
                        'action' => 'CONSERVEE_NON_REPASSEE',
                    ];

                    foreach ($ue->ecues as $ecue) {
                        InscriptionPedagogique::firstOrCreate(
                            [
                                'inscription_id' => $nouvelleInscription->id,
                                'semestre_id' => $ue->semestre_id,
                                'ue_id' => $ue->id,
                                'matiere_id' => $ecue->id,
                            ],
                            [
                                'statut' => 'valide', // déjà acquise
                            ]
                        );
                    }

                    continue;
                }

                // Pour chaque ECUE de l'UE non entièrement capitalisée
                foreach ($ue->ecues as $ecue) {
                    $ecueEstConserve = in_array((int) $ecue->id, $idsEcuesConserves, true);

                    if ($ecueEstConserve) {
                        // ECUE capitalisé avec crédits propres : NON réinscrit pour examen
                        $elementsConserves[] = [
                            'type' => 'ECUE',
                            'id' => $ecue->id,
                            'code' => $ecue->code,
                            'nom' => $ecue->nom,
                            'credits' => (int) $ecue->credits,
                            'action' => 'CONSERVE_NON_REPASSE',
                        ];

                        InscriptionPedagogique::firstOrCreate(
                            [
                                'inscription_id' => $nouvelleInscription->id,
                                'semestre_id' => $ue->semestre_id,
                                'ue_id' => $ue->id,
                                'matiere_id' => $ecue->id,
                            ],
                            [
                                'statut' => 'valide', // capitalisé antérieurement
                            ]
                        );
                    } else {
                        // ECUE non acquis : réinscription active obligatoire
                        $creditsAReprendre += (int) $ecue->credits;
                        $elementsReinscrits[] = [
                            'type' => 'ECUE',
                            'id' => $ecue->id,
                            'code' => $ecue->code,
                            'nom' => $ecue->nom,
                            'credits' => (int) $ecue->credits,
                            'action' => 'REINSCRIT_A_REPRENDRE',
                        ];

                        $ip = InscriptionPedagogique::firstOrCreate(
                            [
                                'inscription_id' => $nouvelleInscription->id,
                                'semestre_id' => $ue->semestre_id,
                                'ue_id' => $ue->id,
                                'matiere_id' => $ecue->id,
                            ],
                            [
                                'statut' => 'inscrit', // à reprendre
                            ]
                        );

                        if ($ip->wasRecentlyCreated) {
                            $inscriptionsCreees++;
                        }
                    }
                }
            }

            // Journal d'audit institutionnel
            HistoriqueAcademique::create([
                'etudiant_id' => $etudiant->id,
                'utilisateur_id' => $agentIdFinal,
                'action' => 'reinscription_pedagogique_redoublant',
                'entite' => 'inscriptions_academiques',
                'entite_id' => $nouvelleInscription->id,
                'ancienne_valeur' => null,
                'nouvelle_valeur' => json_encode([
                    'description' => "Réinscription pédagogique ciblée : {$inscriptionsCreees} enseignements réinscrits à reprendre, {$creditsConserves} crédits d'acquis conservés.",
                    'nombre_elements_reinscrits' => count($elementsReinscrits),
                    'nombre_elements_conserves' => count($elementsConserves),
                    'total_credits_a_reprendre' => $creditsAReprendre,
                    'total_credits_conserves' => $creditsConserves,
                ], JSON_UNESCAPED_UNICODE),
                'source_systeme' => 'web',
            ]);

            return [
                'inscription_academique_id' => $nouvelleInscription->id,
                'etudiant_id' => $etudiant->id,
                'elements_reinscrits' => count($elementsReinscrits),
                'elements_capitalises_ou_compenses' => count($elementsConserves),
                'elements_reinscrits_a_reprendre' => $elementsReinscrits,
                'elements_conserves_non_reinscrits' => $elementsConserves,
                'total_credits_a_reprendre' => $creditsAReprendre,
                'total_credits_conserves' => $creditsConserves,
                'inscriptions_pedagogiques_creees' => $inscriptionsCreees,
            ];
        });
    }

    /**
     * Alias explicite pour réinscription pédagogique sélective.
     */
    public function effectuerReinscriptionPedagogique(
        Etudiant $etudiant,
        InscriptionAcademique $nouvelleInscription,
        ?int $agentId = null
    ): array {
        return $this->reinscrireRedoublant($etudiant, $nouvelleInscription, $agentId);
    }
}
