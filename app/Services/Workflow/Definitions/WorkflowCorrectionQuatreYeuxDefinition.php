<?php

namespace App\Services\Workflow\Definitions;

use App\Models\User;
use App\Services\Workflow\Contrats\WorkflowDefinitionInterface;

/**
 * Définition du workflow de correction à 4 yeux.
 * Cycle : proposee → approuvee → appliquee (ou rejetee).
 * Séparation stricte des fonctions : l'approbateur ne peut JAMAIS être le proposeur.
 */
class WorkflowCorrectionQuatreYeuxDefinition implements WorkflowDefinitionInterface
{
    public function domaine(): string
    {
        return 'correction';
    }

    public function version(): int
    {
        return 1;
    }

    public function etats(): array
    {
        return ['proposee', 'approuvee', 'rejetee', 'appliquee'];
    }

    public function transitions(): array
    {
        return [
            'proposee' => ['approuvee', 'rejetee'],
            'approuvee' => ['appliquee'],
            'rejetee' => [],
            'appliquee' => [],
        ];
    }

    public function transitionAutorisee(string $source, string $cible): bool
    {
        return in_array($cible, $this->transitions()[$source] ?? [], true);
    }

    public function verifierGardes(string $source, string $cible, mixed $entite, ?User $acteur): bool
    {
        // En cas d'approbation ou de rejet, l'acteur ne doit pas être le proposeur
        if (in_array($cible, ['approuvee', 'rejetee'], true) && $acteur !== null && is_object($entite)) {
            $proposePar = $entite->propose_par ?? null;
            if ($proposePar !== null && (int) $proposePar === (int) $acteur->id) {
                return false; // Viol du principe des 4 yeux
            }
        }

        return true;
    }

    public function requiertQuatreYeux(string $source, string $cible): bool
    {
        return in_array($cible, ['approuvee', 'rejetee'], true);
    }

    public function permissionRequise(string $source, string $cible): ?string
    {
        return match ($cible) {
            'approuvee', 'rejetee', 'appliquee' => 'archives.update',
            default => null,
        };
    }

    public function delaiMaxHeures(string $source, string $cible): ?int
    {
        return match ($cible) {
            'approuvee', 'rejetee' => 24,
            'appliquee' => 48,
            default => null,
        };
    }
}
