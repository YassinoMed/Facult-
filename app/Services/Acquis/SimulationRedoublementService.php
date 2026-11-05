<?php

namespace App\Services\Acquis;

use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Modules\Referentiel\Models\Programme;

/**
 * MOTEUR DE SIMULATION DU REDOUBLEMENT ET DES ACQUIS (LOOP 21).
 *
 * Permet de projeter le redoublement en mode dry-run pur sans aucune mutation de données.
 *
 * Supporte :
 * - Simulation individuelle
 * - Simulation multi-étudiants / cohorte
 * - Simulation par programme / formation
 * - Simulation par groupe
 * - Strict déterminisme et reproductibilité intégrale.
 */
class SimulationRedoublementService
{
    public function __construct(
        private readonly MoteurDecisionAcademiqueService $moteurDecision,
        private readonly PlanRedoublementService $planRedoublementService,
    ) {}

    /**
     * Simulation individuelle d'un étudiant.
     *
     * @return array<string, mixed>
     */
    public function simulerIndividuel(Etudiant $etudiant, ?int $anneeUniversitaireId = null): array
    {
        $decision = $this->moteurDecision->determinerDecision($etudiant, $anneeUniversitaireId);
        $plan = $this->planRedoublementService->simulerPlan($etudiant);

        // Calcul du delta avec l'ancienne inscription
        $ancienneInscription = InscriptionAcademique::where('etudiant_id', $etudiant->id)
            ->latest('id')
            ->first();

        $nbUesTotal = count($decision['unites']);
        $nbUesConservees = count($plan['elements_conserves']);
        $nbElementsAReprendre = count($plan['elements_a_reprendre']);

        $empreinteSimulation = hash('sha256', json_encode([
            'etudiant_id' => $etudiant->id,
            'credits_conserves' => $plan['credits_acquis_conserves'],
            'elements_conserves' => $plan['elements_conserves'],
            'elements_a_reprendre' => $plan['elements_a_reprendre'],
        ]));

        return [
            'mode' => 'INDIVIDUEL',
            'etudiant_id' => $etudiant->id,
            'nom_complet' => "{$etudiant->prenom} {$etudiant->nom}",
            'cin' => $etudiant->cin,
            'programme' => $decision['regime']['nom'] ?? 'Programme universitaire',
            'annee_concernee' => $decision['annee_universitaire'],
            'credits' => [
                'acquis_conserves' => $plan['credits_acquis_conserves'],
                'restants_a_valider' => $plan['credits_restants'],
                'total_programme' => $decision['credits']['total_requis'],
            ],
            'kpis' => [
                'total_unites' => $nbUesTotal,
                'unites_conservees' => $nbUesConservees,
                'elements_a_reprendre' => $nbElementsAReprendre,
            ],
            'unites_conservees' => $plan['elements_conserves'],
            'unites_a_reprendre' => $plan['elements_a_reprendre'],
            'prerequis_bloquants' => $plan['prerequis_bloquants'],
            'anomalies' => $decision['anomalies'],
            'delta_ancienne_inscription' => [
                'inscription_id' => $ancienneInscription?->id,
                'moyenne_anterieure' => $ancienneInscription?->moyenne_annuelle,
                'decision_anterieure' => $ancienneInscription?->decision,
                'dispenses_matiere' => $nbUesConservees,
            ],
            'est_validable' => empty($decision['anomalies']) && empty($plan['prerequis_bloquants']),
            'hash_determinisme' => $empreinteSimulation,
            'statut_simulation' => 'SIMULATION_EFFECTUEE_SANS_MUTATION',
        ];
    }

    /**
     * Simulation en lot pour une cohorte d'identifiants d'étudiants.
     *
     * @param  array<int>  $etudiantIds
     * @return array<string, mixed>
     */
    public function simulerCohorte(array $etudiantIds): array
    {
        $etudiants = Etudiant::whereIn('id', $etudiantIds)->get();

        $resultatsIndividuels = [];
        $totalEtudiants = $etudiants->count();
        $totalCreditsSauvegardes = 0;
        $totalElementsARepasser = 0;
        $nbValidables = 0;
        $nbBloques = 0;

        foreach ($etudiants as $etudiant) {
            $sim = $this->simulerIndividuel($etudiant);
            $resultatsIndividuels[] = $sim;

            $totalCreditsSauvegardes += $sim['credits']['acquis_conserves'];
            $totalElementsARepasser += $sim['kpis']['elements_a_reprendre'];

            if ($sim['est_validable']) {
                $nbValidables++;
            } else {
                $nbBloques++;
            }
        }

        return [
            'mode' => 'COHORTE',
            'effectif_total' => $totalEtudiants,
            'synthese' => [
                'etudiants_validables' => $nbValidables,
                'etudiants_avec_blocages' => $nbBloques,
                'moyenne_credits_conserves' => $totalEtudiants > 0 ? round($totalCreditsSauvegardes / $totalEtudiants, 1) : 0,
                'total_elements_a_repasser' => $totalElementsARepasser,
            ],
            'resultats' => $resultatsIndividuels,
        ];
    }

    /**
     * Simulation prévisionnelle pour l'ensemble des étudiants inscrits dans un programme.
     *
     * @return array<string, mixed>
     */
    public function simulerProgramme(int $programmeId, ?int $anneeId = null): array
    {
        $programme = Programme::findOrFail($programmeId);

        $etudiantIds = InscriptionAcademique::where('programme_id', $programmeId)
            ->when($anneeId !== null, fn ($q) => $q->where('annee_universitaire_id', $anneeId))
            ->pluck('etudiant_id')
            ->unique()
            ->all();

        $simulationCohorte = $this->simulerCohorte($etudiantIds);
        $simulationCohorte['programme_id'] = $programme->id;
        $simulationCohorte['programme_nom'] = $programme->nom;

        return $simulationCohorte;
    }

    /**
     * Simulation pour les étudiants d'un groupe spécifique.
     *
     * @return array<string, mixed>
     */
    public function simulerGroupe(int $groupeId): array
    {
        $etudiantIds = Etudiant::where('groupe_id', $groupeId)
            ->pluck('id')
            ->all();

        $simulationCohorte = $this->simulerCohorte($etudiantIds);
        $simulationCohorte['groupe_id'] = $groupeId;

        return $simulationCohorte;
    }
}
