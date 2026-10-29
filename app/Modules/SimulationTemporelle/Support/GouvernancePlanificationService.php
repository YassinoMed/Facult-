<?php

namespace App\Modules\SimulationTemporelle\Support;

use App\Models\User;
use App\Modules\SimulationTemporelle\Models\ExecutionTemporelle;

/**
 * Service de Gouvernance et Validation Humaine à Quatre Yeux (Lot 14).
 *
 * RÈGLES DU MANDAT :
 * - Règle des 2 personnes / 4 yeux : auto-approbation STRICTEMENT INTERDITE ;
 * - Enregistre, trace et fige la proposition sans JAMAIS modifier les tables académiques réelles ;
 * - L'approbation acte la revue humaine de la trajectoire simulée, rien de plus.
 */
final class GouvernancePlanificationService
{
    public const STATUT_SOUMISE = 'soumise';

    public const STATUT_APPROUVEE = 'approuvee';

    public const STATUT_REJETEE = 'rejetee';

    /**
     * Soumet une exécution de simulation à la revue formelle de planification.
     *
     * @param  array<string, mixed>  $justification
     * @return array<string, mixed>
     */
    public function soumettreProposition(
        ExecutionTemporelle $execution,
        User $proposant,
        string $titre,
        string $justification
    ): array {
        $scenario = $execution->scenario;
        if ($scenario?->faculte_id !== null && ! $proposant->peutAccederFaculte((int) $scenario->faculte_id)) {
            throw new \DomainException('Périmètre interdit : vous ne pouvez soumettre de proposition pour cette faculté.');
        }

        // Traçabilité : une proposition existante (soumise OU statuée) n'est
        // JAMAIS écrasée — l'historique d'approbation/rejet reste consultable.
        $existante = ($execution->resultat ?? [])['gouvernance_proposition'] ?? null;
        if ($existante !== null) {
            throw new \DomainException(
                'Une proposition existe déjà sur cette exécution (statut : '.$existante['statut'].'). '
                .'Pour une nouvelle revue, exécuter une nouvelle simulation ou dupliquer le scénario.'
            );
        }

        $proposition = [
            'id' => 'PROP-'.substr($execution->hash_resultat, 0, 8).'-'.time(),
            'execution_temporelle_id' => $execution->id,
            'scenario_id' => $execution->scenario_temporel_id,
            'version_scenario' => $scenario->version,
            'titre' => $titre,
            'justification' => $justification,
            'propose_par_id' => $proposant->id,
            'propose_par_nom' => $proposant->name ?? 'Auteur inconnu',
            'date_soumission' => now()->toIso8601String(),
            'statut' => self::STATUT_SOUMISE,
            'hash_entrees_fige' => $execution->hash_entrees,
            'hash_resultat_fige' => $execution->hash_resultat,
            'historique' => [
                [
                    'action' => 'soumission',
                    'par_id' => $proposant->id,
                    'date' => now()->toIso8601String(),
                    'commentaire' => 'Soumission pour revue à quatre yeux.',
                ],
            ],
            'avertissement' => 'Validation de gouvernance uniquement — aucune application automatique aux données réelles.',
        ];

        // Stockage dans le résultat figé de l'exécution
        $resultat = $execution->resultat ?? [];
        $resultat['gouvernance_proposition'] = $proposition;
        $execution->resultat = $resultat;
        $execution->save();

        return $proposition;
    }

    /**
     * Décide (approuve ou rejette) une proposition soumise avec contrôle strict anti-auto-approbation.
     *
     * @return array<string, mixed>
     */
    public function decider(
        ExecutionTemporelle $execution,
        User $decideur,
        string $decision, // 'approuvee' | 'rejetee'
        string $motif
    ): array {
        $resultat = $execution->resultat ?? [];
        $proposition = $resultat['gouvernance_proposition'] ?? null;

        if ($proposition === null) {
            throw new \DomainException('Aucune proposition en cours sur cette exécution.');
        }

        if ($proposition['statut'] !== self::STATUT_SOUMISE) {
            throw new \DomainException("Cette proposition a déjà été statuée ({$proposition['statut']}).");
        }

        // RÈGLE CARDINALE DU 4-EYES : INTERDICTION DE L'AUTO-APPROBATION
        if ((int) $proposition['propose_par_id'] === (int) $decideur->id) {
            throw new \DomainException('Règle des 4 yeux : l\'auteur de la proposition ne peut pas s\'auto-approuver.');
        }

        $scenario = $execution->scenario;
        if ($scenario?->faculte_id !== null && ! $decideur->peutAccederFaculte((int) $scenario->faculte_id)) {
            throw new \DomainException('Périmètre interdit : vous n\'avez pas accès à la faculté de cette proposition.');
        }

        if (! in_array($decision, [self::STATUT_APPROUVEE, self::STATUT_REJETEE], true)) {
            throw new \DomainException("Décision invalide ({$decision}). Doit être 'approuvee' ou 'rejetee'.");
        }

        // Vérification de non-altération du résultat d'origine
        if ($proposition['hash_resultat_fige'] !== $execution->hash_resultat) {
            throw new \DomainException('Invalidation : le résultat de simulation a été altéré depuis la soumission.');
        }

        $proposition['statut'] = $decision;
        $proposition['statue_par_id'] = $decideur->id;
        $proposition['statue_par_nom'] = $decideur->name ?? 'Décideur';
        $proposition['date_decision'] = now()->toIso8601String();
        $proposition['motif_decision'] = $motif;
        $proposition['historique'][] = [
            'action' => $decision,
            'par_id' => $decideur->id,
            'date' => now()->toIso8601String(),
            'commentaire' => $motif,
        ];

        $resultat['gouvernance_proposition'] = $proposition;
        $execution->resultat = $resultat;
        $execution->save();

        return $proposition;
    }
}
