<?php

namespace App\Services\Workflow\Definitions;

use App\Models\User;
use App\Services\Workflow\Contrats\WorkflowDefinitionInterface;

/**
 * Définition V1 du workflow d'inscription académique.
 * Cycle standard : brouillon → en_attente → valide / ajourne / rejete.
 * Dans la V1, l'état 'valide' est terminal et ne permet aucune révocation ultérieure.
 */
class WorkflowInscriptionDefinitionV1 implements WorkflowDefinitionInterface
{
    public function domaine(): string
    {
        return 'inscription';
    }

    public function version(): int
    {
        return 1;
    }

    public function etats(): array
    {
        return ['brouillon', 'en_attente', 'valide', 'rejete', 'ajourne'];
    }

    public function transitions(): array
    {
        return [
            'brouillon' => ['en_attente', 'rejete'],
            'en_attente' => ['valide', 'rejete', 'ajourne'],
            'ajourne' => ['en_attente', 'rejete'],
            'valide' => [], // Terminal dans la V1
            'rejete' => [],
        ];
    }

    public function transitionAutorisee(string $source, string $cible): bool
    {
        return in_array($cible, $this->transitions()[$source] ?? [], true);
    }

    public function verifierGardes(string $source, string $cible, mixed $entite, ?User $acteur): bool
    {
        // En cas de validation, l'entite ne doit pas avoir de blocage académique actif
        if ($cible === 'valide' && is_object($entite) && method_exists($entite, 'estBloque') && $entite->estBloque()) {
            return false;
        }

        return true;
    }

    public function requiertQuatreYeux(string $source, string $cible): bool
    {
        return $cible === 'valide';
    }

    public function permissionRequise(string $source, string $cible): ?string
    {
        return match ($cible) {
            'valide', 'rejete', 'ajourne' => 'archives.update',
            default => null,
        };
    }

    public function delaiMaxHeures(string $source, string $cible): ?int
    {
        return match ($cible) {
            'en_attente' => 48,
            'valide', 'ajourne', 'rejete' => 72,
            default => null,
        };
    }
}
