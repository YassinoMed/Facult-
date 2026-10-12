<?php

namespace App\Modules\OnlineRequests\Services;

/**
 * Machine à états des demandes (§8) : transitions autorisées
 * explicites, contrôlées serveur. `rejected → completed` et autres
 * sauts sont impossibles par construction.
 */
final class RequestWorkflowService
{
    /**
     * @var array<string, array<int, string>>
     */
    public const TRANSITIONS = [
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

    public static function autorisee(string $depuis, string $vers): bool
    {
        return in_array($vers, self::TRANSITIONS[$depuis] ?? [], true);
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
