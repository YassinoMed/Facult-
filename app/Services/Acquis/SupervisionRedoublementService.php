<?php

namespace App\Services\Acquis;

use App\Models\AnneeUniversitaire;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\ParcoursAcademique\Models\ParcoursPlan;

/**
 * LOOP 34 — Service de supervision et d'observabilité des acquis et redoublements.
 *
 * Détecte proactivement les dérives d'intégrité, incohérences de crédits et
 * non-conformités réglementaires sans jamais exposer de données personnelles (PII) dans les métriques.
 */
class SupervisionRedoublementService
{
    /**
     * Analyse l'intégrité globale du système pour une année universitaire donnée.
     *
     * @return array<string, mixed>
     */
    public function auditerCohorte(?int $anneeUniversitaireId = null): array
    {
        $anneeId = $anneeUniversitaireId ?? AnneeUniversitaire::active()?->id ?? AnneeUniversitaire::latest('id')->value('id');

        $plans = ParcoursPlan::query()
            ->when($anneeId, function ($q) use ($anneeId) {
                $q->whereHas('inscriptionSource', fn ($sub) => $sub->where('annee_universitaire_id', $anneeId));
            })
            ->with(['etudiant', 'inscriptionSource'])
            ->get();

        $anomaliesCritiques = [];
        $avertissements = [];

        // 1. Audit des crédits ECTS et cohérence arithmétique
        foreach ($plans as $plan) {
            $contenu = is_array($plan->contenu) ? $plan->contenu : [];
            $creditsConserves = (int) ($contenu['credits_acquis_conserves'] ?? 0);
            $creditsRestants = (int) ($contenu['credits_restants'] ?? 0);
            $totalCalcule = $creditsConserves + $creditsRestants;
            $totalAttendu = 60; // Standard annuel LMD

            if ($totalCalcule !== $totalAttendu && ($creditsConserves > 0 || $creditsRestants > 0)) {
                $anomaliesCritiques[] = [
                    'code' => 'DISCORDANCE_CREDITS_TOTAL',
                    'plan_id' => $plan->id,
                    'etudiant_id' => $plan->etudiant_id,
                    'details' => sprintf(
                        'Somme crédits (%d conservés + %d restants = %d) différente du total annuel LMD attendu (%d).',
                        $creditsConserves,
                        $creditsRestants,
                        $totalCalcule,
                        $totalAttendu
                    ),
                ];
            }

            // 2. Vérification des éléments à reprendre vs éléments conservés (doublons d'ECUE)
            $ecueReprendre = collect($contenu['elements_a_reprendre'] ?? [])->pluck('id')->filter()->all();
            $ecueConserves = collect($contenu['elements_conserves'] ?? [])->pluck('id')->filter()->all();
            $intersection = array_intersect($ecueReprendre, $ecueConserves);

            if (! empty($intersection)) {
                $anomaliesCritiques[] = [
                    'code' => 'DOUBLON_ELEMENT_CONSERVE_ET_REPRIS',
                    'plan_id' => $plan->id,
                    'etudiant_id' => $plan->etudiant_id,
                    'elements_conflit' => array_values($intersection),
                    'details' => 'Un ou plusieurs éléments sont simultanément déclarés conservés et à reprendre.',
                ];
            }

            // 3. Vérification des alertes de prérequis non résolues
            if (! empty($contenu['prerequis_bloquants'])) {
                $avertissements[] = [
                    'code' => 'PREREQUIS_NON_RESOLU',
                    'plan_id' => $plan->id,
                    'etudiant_id' => $plan->etudiant_id,
                    'details' => 'Des prérequis pédagogiques bloquants restent en suspens.',
                ];
            }
        }

        // 4. Audit croisé Délibération vs Statut du Plan
        $decisions = DecisionDeliberation::query()
            ->where('publie', true)
            ->when($anneeId, function ($q) use ($anneeId) {
                $q->whereHas('inscriptionAcademique', fn ($sub) => $sub->where('annee_universitaire_id', $anneeId));
            })
            ->get();

        foreach ($decisions as $deliberation) {
            $planAssocie = $plans->firstWhere('inscription_source_id', $deliberation->inscription_academique_id);

            // Si admis mais possède un plan de redoublement validé actif
            if (in_array($deliberation->decision, ['admis', 'admis_compensation']) && $planAssocie !== null && $planAssocie->statut === 'valide') {
                $anomaliesCritiques[] = [
                    'code' => 'PLAN_REDOUBLEMENT_SUR_ETUDIANT_ADMIS',
                    'plan_id' => $planAssocie->id,
                    'etudiant_id' => $deliberation->etudiant_id,
                    'decision_deliberation' => $deliberation->decision,
                    'details' => 'Un plan de redoublement actif existe pour un étudiant officiellement déclaré admis.',
                ];
            }

            // Si redoublant mais aucun plan préparé
            if (in_array($deliberation->decision, ['autorise_redoublement', 'redoublement']) && $planAssocie === null) {
                $avertissements[] = [
                    'code' => 'REDOUBLANT_SANS_PLAN',
                    'etudiant_id' => $deliberation->etudiant_id,
                    'details' => 'Étudiant autorisé au redoublement sans plan de réinscription généré.',
                ];
            }
        }

        $nbTotalPlans = $plans->count();
        $nbPlansValides = $plans->where('statut', 'valide')->count();
        $tauxValidation = $nbTotalPlans > 0 ? round(($nbPlansValides / $nbTotalPlans) * 100, 2) : 0;

        return [
            'annee_universitaire_id' => $anneeId,
            'statistiques' => [
                'total_plans' => $nbTotalPlans,
                'plans_brouillons' => $plans->where('statut', 'brouillon')->count(),
                'plans_proposes' => $plans->where('statut', 'propose')->count(),
                'plans_valides' => $nbPlansValides,
                'plans_archives' => $plans->where('statut', 'archive')->count(),
                'taux_validation_pourcent' => $tauxValidation,
            ],
            'anomalies_critiques_count' => count($anomaliesCritiques),
            'avertissements_count' => count($avertissements),
            'anomalies_critiques' => $anomaliesCritiques,
            'avertissements' => $avertissements,
            'conforme' => count($anomaliesCritiques) === 0,
            'horodatage' => now()->toIso8601String(),
        ];
    }
}
