<?php

namespace App\Modules\AssistantIa\Tools\Metier;

use App\Models\User;
use App\Modules\AssistantIa\Contracts\OutilIaInterface;
use App\Modules\AssistantIa\DTO\ResultatOutil;
use App\Modules\AssistantIa\Exceptions\SecuriteIaException;
use App\Modules\AssistantIa\Services\FiltreSecuriteIa;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Services\ExamDecisionCapacitePermutationService;

/**
 * Outil métier AssistantIa : Comparaison des scénarios D-04 de permutation de salles et amphis.
 * Analyse les jauges utiles, capacités résiduelles et conflits horaires pour recommander la meilleure option.
 */
class ComparerScenariosD04Tool implements OutilIaInterface
{
    public function __construct(
        protected ?ExamDecisionCapacitePermutationService $serviceDecisionCapacite = null
    ) {
        $this->serviceDecisionCapacite = $serviceDecisionCapacite ?? app(ExamDecisionCapacitePermutationService::class);
    }

    public function nom(): string
    {
        return 'comparer_scenarios_d04';
    }

    public function description(): string
    {
        return 'Compare les propositions et scénarios D-04 de permutation de salles et amphithéâtres pour une épreuve.';
    }

    public function mode(): string
    {
        return 'metier';
    }

    public function permissionRequise(): string
    {
        return 'assistant_ia.mode_metier';
    }

    public function schemaParametres(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'epreuve_id' => [
                    'type' => 'integer',
                    'description' => 'Identifiant numérique de l\'épreuve d\'examen',
                ],
            ],
            'required' => ['epreuve_id'],
        ];
    }

    public function estSensible(): bool
    {
        return false;
    }

    public function executer(array $parametres, User $utilisateur): ResultatOutil
    {
        $epreuveId = (int) ($parametres['epreuve_id'] ?? 0);
        if ($epreuveId <= 0) {
            return ResultatOutil::echec("L'identifiant de l'épreuve est obligatoire et doit être un entier positif.");
        }

        $epreuve = Epreuve::with(['matiere.ue.programme', 'examen.session', 'salle'])->find($epreuveId);
        if (! $epreuve) {
            return ResultatOutil::echec("Épreuve #{$epreuveId} introuvable.");
        }

        // Contrôle d'habilitation et BOLA facultaire
        if (! $utilisateur->hasPermission('assistant_ia.mode_metier') && ! $utilisateur->estSuperAdmin()) {
            throw new SecuriteIaException("Vous ne disposez pas de l'habilitation requise pour comparer les scénarios de salles D-04.");
        }

        $faculteRestreinte = $utilisateur->faculteRestreinteId();
        $faculteEpreuve = $epreuve->matiere?->ue?->programme?->faculte_id
            ?? $epreuve->examen?->programme?->faculte_id;

        if ($faculteRestreinte !== null && $faculteEpreuve !== null) {
            FiltreSecuriteIa::verifierPerimetreFaculte($utilisateur, $faculteEpreuve);
        }

        // Appel du moteur canonique D-04
        $diagnostic = $this->serviceDecisionCapacite->diagnostic(
            $epreuve->annee_universitaire_id,
            $faculteEpreuve,
            $epreuve->session_id,
            $epreuve->examen_id,
            $epreuve->id
        );

        $ligne = $diagnostic['lignes'][$epreuveId] ?? null;
        $variantes = is_array($ligne) ? ($ligne['variantes'] ?? []) : [];
        $comparaison = $this->serviceDecisionCapacite->comparerVariantes(is_array($variantes) ? $variantes : []);

        $scenariosResumes = [];

        foreach ($comparaison['classement'] as $variante) {
            $scenariosResumes[] = [
                'rang' => (int) ($variante['rang'] ?? 0),
                'index' => (int) ($variante['rang'] ?? 0),
                'uuid' => $variante['uuid'] ?? ($variante['cle'] ?? null),
                'strategie' => $variante['type'] ?? 'combinaison',
                'salles' => array_map(fn ($s) => $s['code'] ?? 'Salle', $variante['salles'] ?? []),
                'type_salles' => $variante['type_libelle'] ?? 'Combinaison de salles',
                'capacite_utile' => (int) ($variante['capacite_utile_totale'] ?? 0),
                'effectif_a_placer' => (int) ($variante['effectif_couvert'] ?? 0),
                'marge_residuelle' => (int) ($variante['places_restantes'] ?? 0),
                'depassement' => (int) ($variante['depassement'] ?? 0),
                'taux_remplissage_pct' => $variante['taux_utilisation'] ?? null,
                'conflits_count' => (int) ($variante['conflits_count'] ?? 0),
                'score' => $variante['score'] ?? null,
                'recommandable' => (bool) ($variante['viable'] ?? false),
            ];
        }

        $meilleurScenario = null;
        foreach ($scenariosResumes as $scenario) {
            if ($scenario['recommandable']) {
                $meilleurScenario = $scenario;

                break;
            }
        }

        $salleActuelle = $epreuve->salle ? "{$epreuve->salle->code} ({$epreuve->salle->capacite} places)" : 'Aucune salle';
        $nbScenarios = count($scenariosResumes);

        $resume = "Épreuve #{$epreuveId} : Salle actuelle = {$salleActuelle}. {$nbScenarios} scénario(s) D-04 classé(s). "
            .($meilleurScenario
                ? "Option la mieux classée (indicatif) : rang #{$meilleurScenario['rang']} ({$meilleurScenario['type_salles']} - marge : +{$meilleurScenario['marge_residuelle']} places)."
                : 'Aucun scénario viable sans dépassement ni conflit.');

        $donneesSortie = [
            'epreuve_id' => $epreuveId,
            'salle_actuelle' => $salleActuelle,
            'effectif_requis' => $epreuve->candidats()->count() ?: 30,
            'total_scenarios' => $nbScenarios,
            'scenarios' => $scenariosResumes,
            'classement_d04' => array_slice($comparaison['classement'], 0, 10),
            'criteres_classement' => $comparaison['criteres'],
            'recommandation' => $meilleurScenario,
            'avertissement_classement' => 'Classement indicatif : aucune variante n’est officiellement retenue, validée ni publiée par cet outil. L’application d’une permutation exige une validation humaine formelle.',
            'lecture_seule' => true,
            'note_gouvernance' => 'L\'AssistantIa présente une aide à la décision. L\'application officielle de permutation nécessite une validation humaine formelle dans le module Salles D-04.',
        ];

        $sources = [
            [
                'titre' => 'Moteur de Décision Capacitaire et Permutation de Salles (D-04)',
                'url_ou_chemin' => 'app/Modules/Evaluation/Services/ExamDecisionCapacitePermutationService.php',
                'source' => 'business_procedure',
            ],
            [
                'titre' => 'Règle de Jauge Utile et Non-Dépassement de Capacité UniRegistre',
                'url_ou_chemin' => 'docs/regles-metier/gestion-capacitaire-salles.md',
                'source' => 'business_procedure',
            ],
        ];

        return ResultatOutil::succes($donneesSortie, $resume, $sources);
    }
}
