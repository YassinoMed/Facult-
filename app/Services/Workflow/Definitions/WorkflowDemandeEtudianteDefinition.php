<?php

namespace App\Services\Workflow\Definitions;

use App\Models\User;
use App\Services\Workflow\Contrats\WorkflowDefinitionInterface;

/**
 * Définition du workflow de traitement des demandes en ligne (OnlineRequests).
 * Machine à états finis stricte avec délais SLA.
 */
class WorkflowDemandeEtudianteDefinition implements WorkflowDefinitionInterface
{
    public function domaine(): string
    {
        return 'demande';
    }

    public function version(): int
    {
        return 1;
    }

    public function etats(): array
    {
        return [
            'draft', 'submitted', 'received', 'in_review',
            'waiting_student', 'approved', 'rejected', 'cancelled', 'completed',
        ];
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
        return true;
    }

    public function requiertQuatreYeux(string $source, string $cible): bool
    {
        return $cible === 'approved';
    }

    /**
     * Permission requise pour franchir la transition — matrice réelle du
     * module (routes + policy) : approbation/rejet sont des pouvoirs de
     * validation distincts du traitement ; les actions du portail
     * étudiant (submitted, cancelled) ne portent aucune permission
     * (l'appartenance est vérifiée au contrôleur).
     */
    public function permissionRequise(string $source, string $cible): ?string
    {
        return match ($cible) {
            'approved' => 'demandes.approve',
            'rejected' => 'demandes.reject',
            'received', 'in_review', 'waiting_student', 'completed' => 'demandes.process',
            default => null,
        };
    }

    public function delaiMaxHeures(string $source, string $cible): ?int
    {
        return match ($cible) {
            'received' => 24,
            'in_review' => 48,
            'approved', 'rejected' => 72,
            default => null,
        };
    }
}
