<?php

namespace App\Services\Workflow\Definitions;

use App\Models\User;
use App\Services\Workflow\Contrats\WorkflowDefinitionInterface;

/**
 * Définition V2 du workflow d'inscription académique.
 * Évolutions par rapport à la V1 :
 * 1. Ajout de l'état intermédiaire 'pieces_attendues'.
 * 2. Ajout de la transition post-validation 'valide' → 'revoquee' (révocation motivée exceptionnelle).
 */
class WorkflowInscriptionDefinitionV2 implements WorkflowDefinitionInterface
{
    public function domaine(): string
    {
        return 'inscription';
    }

    public function version(): int
    {
        return 2;
    }

    public function etats(): array
    {
        return ['brouillon', 'en_attente', 'pieces_attendues', 'valide', 'rejete', 'ajourne', 'revoquee'];
    }

    public function transitions(): array
    {
        return [
            'brouillon' => ['en_attente', 'rejete'],
            'en_attente' => ['pieces_attendues', 'valide', 'rejete', 'ajourne'],
            'pieces_attendues' => ['en_attente', 'rejete'],
            'ajourne' => ['en_attente', 'rejete'],
            'valide' => ['revoquee'], // Révocation possible uniquement en V2
            'revoquee' => [],
            'rejete' => [],
        ];
    }

    public function transitionAutorisee(string $source, string $cible): bool
    {
        return in_array($cible, $this->transitions()[$source] ?? [], true);
    }

    public function verifierGardes(string $source, string $cible, mixed $entite, ?User $acteur): bool
    {
        if ($cible === 'valide' && is_object($entite) && method_exists($entite, 'estBloque') && $entite->estBloque()) {
            return false;
        }

        return true;
    }

    public function requiertQuatreYeux(string $source, string $cible): bool
    {
        return in_array($cible, ['valide', 'revoquee'], true);
    }

    public function permissionRequise(string $source, string $cible): ?string
    {
        return match ($cible) {
            'valide', 'rejete', 'ajourne' => 'archives.update',
            'revoquee' => 'configuration.manage',
            default => null,
        };
    }

    public function delaiMaxHeures(string $source, string $cible): ?int
    {
        return match ($cible) {
            'en_attente' => 48,
            'pieces_attendues' => 120,
            'valide', 'ajourne', 'rejete' => 72,
            default => null,
        };
    }
}
