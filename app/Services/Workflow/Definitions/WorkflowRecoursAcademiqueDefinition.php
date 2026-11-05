<?php

namespace App\Services\Workflow\Definitions;

use App\Models\User;
use App\Services\Workflow\Contrats\WorkflowDefinitionInterface;

/**
 * LOOP 45 — WORKFLOW DES RECOURS ACADÉMIQUES (version 1).
 *
 * Le circuit réutilise les statuts de `online_requests` (aucune table
 * supplémentaire) mais possède sa propre topologie, ses propres permissions
 * et sa propre règle de quatre yeux.
 *
 * Principes :
 * - aucun délai SLA n'est inventé : `delaiMaxHeures()` renvoie toujours null ;
 * - la recevabilité est un acte humain explicite, jamais un calcul automatique ;
 * - une décision (approbation ou rejet) exige une instruction préalable et
 *   deux agents distincts (l'instructeur assigné ne décide pas) ;
 * - la clôture exige qu'une application manuelle ait été enregistrée ;
 * - aucune transition ne modifie une note ou une décision de délibération.
 */
class WorkflowRecoursAcademiqueDefinition implements WorkflowDefinitionInterface
{
    public const DOMAINE = 'recours';

    public const VERSION = 1;

    public const STATUTS = [
        'draft', 'submitted', 'received', 'in_review',
        'waiting_student', 'approved', 'rejected', 'cancelled', 'completed',
    ];

    public function domaine(): string
    {
        return self::DOMAINE;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function etats(): array
    {
        return self::STATUTS;
    }

    public function transitions(): array
    {
        return [
            'draft' => ['submitted', 'cancelled'],
            'submitted' => ['received', 'cancelled'],
            'received' => ['in_review', 'cancelled'],
            'in_review' => ['waiting_student', 'approved', 'rejected'],
            'waiting_student' => ['in_review', 'cancelled'],
            'approved' => ['completed'],
            'rejected' => [],
            'cancelled' => [],
            'completed' => [],
        ];
    }

    public function transitionAutorisee(string $source, string $cible): bool
    {
        return in_array($cible, $this->transitions()[$source] ?? [], true);
    }

    public function verifierGardes(string $source, string $cible, mixed $entite, ?User $acteur): bool
    {
        $recours = $this->recours($entite);

        if ($recours === null) {
            return false;
        }

        return match ($cible) {
            'submitted' => $this->gardesDepot($recours),
            'received' => ($recours['recevabilite'] ?? null) === 'RECEVABLE',
            'in_review' => $source === 'waiting_student'
                || $this->assignedTo($entite) !== null,
            'waiting_student' => $this->assignedTo($entite) !== null,
            'approved', 'rejected' => $this->gardesDecision($entite, $recours),
            'completed' => ($recours['application_effectuee'] ?? false) === true
                && trim((string) ($recours['reference_application'] ?? '')) !== '',
            'cancelled' => trim((string) ($recours['annulation']['motif'] ?? '')) !== '',
            default => true,
        };
    }

    /**
     * Quatre yeux sur toute décision : l'instructeur assigné ne peut pas
     * approuver ni rejeter lui-même.
     */
    public function requiertQuatreYeux(string $source, string $cible): bool
    {
        return in_array($cible, ['approved', 'rejected'], true);
    }

    public function permissionRequise(string $source, string $cible): ?string
    {
        return match ($cible) {
            'received' => 'recours.recevoir',
            'in_review' => 'recours.instruire',
            'waiting_student' => 'recours.instruire',
            'approved', 'rejected' => 'recours.decider',
            'completed' => 'recours.appliquer',
            'cancelled' => 'recours.annuler',
            default => null,
        };
    }

    /**
     * Aucun SLA officiel n'étant fourni, aucune transition n'est bornée.
     */
    public function delaiMaxHeures(string $source, string $cible): ?int
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $recours
     */
    private function gardesDepot(array $recours): bool
    {
        if (config('recours_academiques.actif') !== true) {
            return false;
        }

        if (trim((string) config('recours_academiques.reference_reglementaire')) === '') {
            return false;
        }

        if ((int) ($recours['decision_id'] ?? 0) <= 0) {
            return false;
        }

        return trim((string) ($recours['motif'] ?? '')) !== '';
    }

    /**
     * @param  array<string, mixed>  $recours
     */
    private function gardesDecision(mixed $entite, array $recours): bool
    {
        if ($this->assignedTo($entite) === null) {
            return false;
        }

        if (trim((string) ($recours['instruction']['par'] ?? '')) === '') {
            return false;
        }

        if (config('recours_academiques.application_automatique_autorisee') === true) {
            return false;
        }

        return trim((string) ($recours['decision']['motif'] ?? '')) !== '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function recours(mixed $entite): ?array
    {
        $donnees = $entite->form_data ?? null;

        if (! is_array($donnees) || ! isset($donnees['recours']) || ! is_array($donnees['recours'])) {
            return null;
        }

        return $donnees['recours'];
    }

    private function assignedTo(mixed $entite): ?int
    {
        $valeur = $entite->assigned_to ?? null;

        return $valeur === null ? null : (int) $valeur;
    }
}
