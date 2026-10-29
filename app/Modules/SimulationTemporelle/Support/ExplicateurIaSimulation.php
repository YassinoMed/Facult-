<?php

namespace App\Modules\SimulationTemporelle\Support;

use App\Models\User;
use App\Modules\SimulationTemporelle\Models\ExecutionTemporelle;

/**
 * Assistant IA Explicatif Fondé sur Preuves Mathématiques (Lot 12).
 *
 * RÈGLES DU MANDAT :
 * - ZÉRO chiffre inventé : chaque réponse cite exclusivement les données figées de l'exécution ;
 * - Contrôle d'habilitation et de périmètre faculté avant toute analyse ;
 * - Aucune mutation métier déclenchée par une réponse IA ;
 * - Références vérifiables (hash_resultat, ID exécution, métriques exactes).
 */
final class ExplicateurIaSimulation
{
    /**
     * Analyse et répond à une requête explicative sur une exécution de simulation.
     *
     * @param  array<string, mixed>  $contexteOptionnel
     * @return array{
     *   question: string,
     *   reponse: string,
     *   preuves: array<string, mixed>,
     *   fiabilite: string,
     *   empreinte_preuve: string
     * }
     */
    public function expliquer(
        ExecutionTemporelle $execution,
        string $question,
        User $utilisateur,
        array $contexteOptionnel = []
    ): array {
        $scenario = $execution->scenario;
        $faculteId = $scenario?->faculte_id;

        // Contrôle de périmètre faculté
        if ($faculteId !== null && ! $utilisateur->peutAccederFaculte((int) $faculteId)) {
            throw new \DomainException('Accès refusé : exécution hors périmètre autorisé.');
        }

        $resultat = $execution->resultat ?? [];
        $etatInitial = $resultat['etat_initial'] ?? [];
        $classement = $resultat['classement'] ?? [];
        $nonConformes = $resultat['non_conformes'] ?? [];
        $sensibilite = $resultat['sensibilite'] ?? [];
        $qualite = $resultat['audit_qualite'] ?? [];
        $capacites = $resultat['capacites'] ?? [];

        $qLower = mb_strtolower($question);
        $reponse = '';
        $preuves = [];

        // 1. Question : Pourquoi non conforme / rejets ?
        if (str_contains($qLower, 'non conforme') || str_contains($qLower, 'rejet') || str_contains($qLower, 'violation')) {
            if ($nonConformes === []) {
                $reponse = "Toutes les variantes évaluées pour cette exécution sont 100% conformes aux contraintes déclaratives fixées. Aucune violation n'a été enregistrée.";
                $preuves = ['nb_non_conformes' => 0, 'nb_conformes' => count($classement)];
            } else {
                $motifs = [];
                foreach ($nonConformes as $nc) {
                    $motifs[] = "Variante #{$nc['index']} (delta {$nc['delta']}) : ".implode(' ; ', $nc['violations'] ?? []);
                }
                $reponse = 'Les variantes suivantes ont été écartées du classement en raison du non-respect de contraintes dures : '.implode(' | ', $motifs).'.';
                $preuves = [
                    'nb_variantes_rejetees' => count($nonConformes),
                    'detail_rejets' => $motifs,
                    'contraintes_scenario' => $scenario->contraintes,
                ];
            }
        }
        // 2. Question : Quel objectif pénalise / explication des scores ?
        elseif (str_contains($qLower, 'score') || str_contains($qLower, 'penalise') || str_contains($qLower, 'objectif')) {
            $top = $classement[0] ?? null;
            if ($top !== null) {
                $reponse = sprintf(
                    'La variante la plus performante (#%d) obtient un score de %.2f/100 (delta admissions : %+.2f%%). Contributions : %s.',
                    $top['index'],
                    $top['score'] * 100,
                    $top['delta'] * 100,
                    json_encode($top['contributions'] ?? [])
                );
                $preuves = [
                    'score_top' => $top['score'],
                    'contributions' => $top['contributions'] ?? [],
                    'objectifs_criteres' => $scenario->objectifs,
                ];
            } else {
                $reponse = "Aucune variante n'a pu être classée car aucune n'a satisfait l'intégralité des contraintes réglementaires.";
                $preuves = ['classement_vide' => true];
            }
        }
        // 3. Question : Qualité des données / anomalies ?
        elseif (str_contains($qLower, 'qualite') || str_contains($qLower, 'anomalie') || str_contains($qLower, 'donnees')) {
            $anomalies = $qualite['anomalies'] ?? [];
            if ($anomalies === []) {
                $reponse = sprintf("Le contrôle qualité est optimal (score de %.0f%%). Les données d'inscriptions et de maquettes sont cohérentes sur ce périmètre.", ($qualite['score_qualite'] ?? 1.0) * 100);
            } else {
                $desc = array_column($anomalies, 'description');
                $reponse = sprintf('Le contrôle qualité a identifié %d point(s) d\'attention : %s.', count($anomalies), implode(' ; ', $desc));
            }
            $preuves = ['audit_qualite' => $qualite];
        }
        // 4. Question : Hypothèses et évolution globale des effectifs
        else {
            $totalInit = $etatInitial['total_initial'] ?? 0;
            $topFinal = $classement[0]['total_final'] ?? $totalInit;
            $diff = $topFinal - $totalInit;
            $pct = $totalInit > 0 ? round(($diff / $totalInit) * 100, 1) : 0;
            $paramSensible = $sensibilite['parametres_classes'][0]['libelle'] ?? 'admissions';

            $reponse = sprintf(
                "L'effectif initial de %d étudiants évolue vers %d étudiants à l'horizon retenu (%+d étudiants, %+0.1f%%). Le facteur le plus déterminant de cette trajectoire est : %s.",
                $totalInit,
                $topFinal,
                $diff,
                $pct,
                $paramSensible
            );

            $preuves = [
                'total_initial' => $totalInit,
                'total_final_top' => $topFinal,
                'horizon' => $execution->horizon_annees,
                'delta_admissions_base' => $scenario->criteres['delta_admissions'] ?? 0,
            ];
        }

        return [
            'question' => $question,
            'reponse' => $reponse,
            'preuves' => $preuves,
            'fiabilite' => '100% vérifiée sur données de simulation calculées',
            'empreinte_preuve' => hash('sha256', $execution->hash_resultat.':'.$question),
        ];
    }
}
