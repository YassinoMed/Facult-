<?php

namespace App\Services\DataExchange;

use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportHandler;

/**
 * Phase 17 §17.1 : adaptateur non-dupliquant — expose chaque
 * `ImportDefinitionInterface` du registre existant comme `ImportHandler`.
 */
final class DefinitionImportHandlerAdapter implements ImportHandler
{
    public function __construct(
        private readonly string $entite,
        private readonly ImportDefinitionInterface $definition,
    ) {}

    public function entite(): string
    {
        return $this->entite;
    }

    public function champs(): array
    {
        return $this->definition->colonnes();
    }

    public function champsRequis(): array
    {
        return $this->definition->colonnesRequises();
    }

    public function cleCorrespondance(): array
    {
        return $this->definition->cleDoublon();
    }

    public function regles(): array
    {
        return $this->definition->regles();
    }

    public function modes(): array
    {
        return $this->definition->modes();
    }
}
