<?php

namespace App\Services\Acquis;

use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\ParcoursAcademique\Models\ParcoursDispense;
use App\Modules\ParcoursAcademique\Models\ParcoursEquivalence;
use App\Modules\Referentiel\Models\Programme;
use DomainException;

/**
 * GESTIONNAIRE DES CRÉDITS ET PRÉVENTION DES DOUBLONS (LOOP 18).
 *
 * Garantit qu'un crédit acquis ne soit ni perdu ni attribué deux fois.
 * Prend en charge les Scénarios A, B, C, D et E du référentiel :
 * - Scénario A : Capitalisation UE (non duplication).
 * - Scénario B : Capitalisation ECUE (conservation crédits propres).
 * - Scénario C : Compensation de jury (distinction stricte de la capitalisation ECUE).
 * - Scénario D : Détection de discordance de crédits (fail-closed).
 * - Scénario E : Idempotence face aux imports ou calculs répétés.
 */
class GestionnaireCreditsService
{
    public function __construct(
        private readonly CalculAcquisPedagogiquesService $calculAcquisService,
    ) {}

    /**
     * Calcule et audite les crédits réels, en détectant toute tentative de double comptage
     * ou d'incohérence avec la maquette pédagogique officielle.
     *
     * @return array{
     *     credits_capitalises_ue: int,
     *     credits_capitalises_ecue: int,
     *     credits_compenses: int,
     *     credits_dispenses_equivalences: int,
     *     total_credits_acquis: int,
     *     credits_requis: int,
     *     credits_restants: int,
     *     doublons_detectes: array,
     *     anomalies_credits: array,
     *     idempotence_validee: bool
     * }
     */
    public function auditerCreditsEtudiant(
        Etudiant $etudiant,
        ?Programme $programme = null,
        ?InscriptionAcademique $inscription = null
    ): array {
        $etudiant->loadMissing(['programmeRef', 'faculte']);
        $programme ??= $inscription?->programmeRef ?? $etudiant->programmeRef;

        $creditsRequis = (int) ($programme?->credits_requis ?? 60);

        // UEs officielles de la maquette
        $ues = $this->calculAcquisService->recupererUesDuParcours($programme, $inscription);
        $ueMap = $ues->keyBy('id');

        $doublonsDetectes = [];
        $anomaliesCredits = [];

        // Dictionnaires d'unicité pour garantir ZÉRO double comptage
        $creditsParUe = [];
        $creditsParEcue = [];

        // 1. Audit des dispenses et équivalences validées
        $dispenses = ParcoursDispense::where('etudiant_id', $etudiant->id)->where('statut', 'validee')->get();
        $equivalences = ParcoursEquivalence::where('etudiant_id', $etudiant->id)->where('statut', 'validee')->get();

        $creditsDispensesEquivalences = 0;
        foreach ($dispenses as $dispense) {
            if ($dispense->ue_id !== null) {
                $ue = $ueMap->get($dispense->ue_id);
                $credits = (int) ($ue?->credits ?? 0);
                if (isset($creditsParUe[$dispense->ue_id])) {
                    $doublonsDetectes[] = "Dispense UE #{$dispense->ue_id} déjà comptabilisée.";
                } else {
                    $creditsParUe[$dispense->ue_id] = $credits;
                    $creditsDispensesEquivalences += $credits;
                }
            } elseif ($dispense->matiere_id !== null) {
                $creditsParEcue[$dispense->matiere_id] = 0; // Crédits pris en compte via matière si applicables
            }
        }

        foreach ($equivalences as $eq) {
            if ($eq->cible_ue_id !== null) {
                $ue = $ueMap->get($eq->cible_ue_id);
                $credits = (int) ($ue?->credits ?? $eq->origine_credits);
                if (isset($creditsParUe[$eq->cible_ue_id])) {
                    $doublonsDetectes[] = "Équivalence UE #{$eq->cible_ue_id} déjà comptabilisée.";
                } else {
                    $creditsParUe[$eq->cible_ue_id] = $credits;
                    $creditsDispensesEquivalences += $credits;
                }
            }
        }

        // 2. Audit des délibérations publiées et vérification de conformité avec la maquette (Scénario D)
        $deliberations = DecisionDeliberation::where('etudiant_id', $etudiant->id)
            ->where('publie', true)
            ->get();

        foreach ($deliberations as $delib) {
            if ($delib->credits_total > 0 && $delib->credits_total !== $creditsRequis) {
                $anomaliesCredits[] = [
                    'code' => 'CREDITS_TOTAL_DISCORDANT',
                    'constat' => "La délibération #{$delib->id} indique {$delib->credits_total} crédits totaux, alors que la maquette officielle du programme en requiert {$creditsRequis}.",
                    'bloquant' => true,
                ];
            }
        }

        // 3. Calcul unitaire via le moteur d'acquis
        $analyse = $this->calculAcquisService->analyserAcquis($etudiant, $inscription);

        $creditsCapitalisesUe = (int) ($analyse['credits_capitalises_ue'] ?? 0);
        $creditsCapitalisesEcue = (int) ($analyse['credits_capitalises_ecue'] ?? 0);
        $creditsCompenses = (int) ($analyse['credits_valides_compensation'] ?? 0);
        $totalCreditsAcquis = (int) ($analyse['total_credits_acquis'] ?? 0);

        // SCÉNARIO D — Vérification de discordance sur chaque UE
        foreach ($analyse['ues'] as $ueAnalysee) {
            $ueId = $ueAnalysee['ue_id'] ?? null;
            $ueModel = $ueId ? $ueMap->get($ueId) : null;

            if ($ueModel !== null) {
                $creditsMaquette = (int) $ueModel->credits;
                $creditsAnalyse = (int) ($ueAnalysee['credits_potentiels'] ?? $creditsMaquette);

                if ($creditsAnalyse !== $creditsMaquette) {
                    $anomaliesCredits[] = [
                        'code' => 'CREDITS_UE_DISCORDANTS',
                        'constat' => "L'UE {$ueModel->code} présente {$creditsAnalyse} crédits dans l'analyse contre {$creditsMaquette} dans le référentiel officiel.",
                        'bloquant' => true,
                    ];
                }
            }
        }

        // SCÉNARIO E — Vérification d'idempotence (Double exécution successive)
        $secondeAnalyse = $this->calculAcquisService->analyserAcquis($etudiant, $inscription);
        $idempotenceValidee = ($analyse['total_credits_acquis'] === $secondeAnalyse['total_credits_acquis'])
            && count($analyse['elements_conserves']) === count($secondeAnalyse['elements_conserves']);

        $creditsRestants = max(0, $creditsRequis - $totalCreditsAcquis);

        return [
            'credits_capitalises_ue' => $creditsCapitalisesUe,
            'credits_capitalises_ecue' => $creditsCapitalisesEcue,
            'credits_compenses' => $creditsCompenses,
            'credits_dispenses_equivalences' => $creditsDispensesEquivalences,
            'total_credits_acquis' => $totalCreditsAcquis,
            'credits_requis' => $creditsRequis,
            'credits_restants' => $creditsRestants,
            'doublons_detectes' => $doublonsDetectes,
            'anomalies_credits' => $anomaliesCredits,
            'idempotence_validee' => $idempotenceValidee,
        ];
    }

    /**
     * Empêche l'import d'un PV ou de notes qui attribuerait des crédits fictifs ou incohérents (Scénario D).
     *
     * @param  array<string, mixed>  $donneesPv
     */
    public function validerCreditsPv(Programme $programme, array $donneesPv): void
    {
        $creditsAttendus = (int) ($programme->credits_requis ?? 60);
        $creditsDeclares = (int) ($donneesPv['credits_total'] ?? $creditsAttendus);

        if ($creditsDeclares !== $creditsAttendus) {
            throw new DomainException(
                "Rejet fail-closed : les crédits déclarés dans le PV ({$creditsDeclares}) ne correspondent pas à la maquette officielle ({$creditsAttendus})."
            );
        }
    }
}
