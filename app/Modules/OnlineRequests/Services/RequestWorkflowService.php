<?php

namespace App\Modules\OnlineRequests\Services;

use App\Services\Workflow\Definitions\WorkflowDemandeEtudianteDefinition;

/**
 * Machine à états des demandes (§8) : transitions autorisées
 * explicites, contrôlées serveur. `rejected → completed` et autres
 * sauts sont impossibles par construction.
 *
 * Source unique de la topologie depuis le LOT 01 :
 * `WorkflowDemandeEtudianteDefinition` (moteur de workflows
 * universitaires, versionnée). Ce helper ne conserve que la façade
 * statique historique du module et les libellés d'affichage.
 */
final class RequestWorkflowService
{
    public static function autorisee(string $depuis, string $vers): bool
    {
        return (new WorkflowDemandeEtudianteDefinition)->transitionAutorisee($depuis, $vers);
    }

    /**
     * Libellés français pour l'interface (pastille + texte, cf. composant).
     *
     * @return array<string, string>
     */
    public static function libelles(): array
    {
        return [
            'draft' => 'Brouillon',
            'submitted' => 'Soumise',
            'received' => 'Reçue',
            'in_review' => 'En cours',
            'waiting_student' => 'Pièce attendue',
            'approved' => 'Approuvée',
            'rejected' => 'Rejetée',
            'cancelled' => 'Annulée',
            'completed' => 'Terminée',
        ];
    }
}
