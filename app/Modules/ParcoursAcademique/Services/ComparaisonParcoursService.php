<?php

namespace App\Modules\ParcoursAcademique\Services;

use App\Models\Etudiant;
use Illuminate\Support\Collection;

/**
 * Comparaison de parcours (§14) : étudiants ou scénarios,
 * progression, crédits, moyennes, dettes, validations,
 * retards, réorientations, décisions. Lecture seule,
 * appelants responsables des permissions/confidentialité.
 */
class ComparaisonParcoursService
{
    public function __construct(
        private readonly CreditParcoursService $credits,
        private readonly DetteAcademiqueService $dettes,
        private readonly RisqueAcademiqueService $risques,
        private readonly PlanParcoursService $plans,
    ) {}

    /**
     * @param  array<int, int>  $etudiantIds
     * @return array<int, array<string, mixed>>
     */
    public function comparerEtudiants(array $etudiantIds): array
    {
        $etudiants = Etudiant::whereIn('id', array_values(array_unique($etudiantIds)))
            ->with(['programmeRef', 'niveauRef', 'faculte'])->get();

        return $etudiants->map(function (Etudiant $etudiant) {
            $credits = $this->credits->creditsEtudiant($etudiant);
            $dettes = $this->dettes->lister($etudiant);
            $risque = $this->risques->evaluer($etudiant);
            $analyse = $this->plans->analyser($etudiant);

            return [
                'etudiant_id' => $etudiant->id,
                'nom_complet' => $etudiant->nom_complet ?? ($etudiant->nom.' '.$etudiant->prenom),
                'programme' => $analyse['programme'],
                'niveau_actuel' => $analyse['niveau_actuel'],
                'credits_obtenus' => $credits['obtenus'],
                'credits_requis' => $credits['requis'],
                'moyenne_generale' => $credits['moyenne_generale'],
                'dettes' => count($dettes),
                'risque' => $risque['niveau'],
                'proposition' => $analyse['propositions'][0]['scenario'] ?? null,
            ];
        })->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $resultatsSimulations  (champ resultat brut)
     * @return array<string, mixed>
     */
    public function comparerScenarios(Collection $simulations): array
    {
        return [
            'scenarios' => $simulations->map(fn ($s) => [
                'id' => $s->id ?? null,
                'scenario' => $s->scenario ?? $s['scenario'] ?? null,
                'resultat_final' => $s->resultat['resultat_final'] ?? $s['resultat_final'] ?? null,
                'credits_manquants' => $s->resultat['credits_manquants'] ?? null,
            ])->all(),
        ];
    }
}
