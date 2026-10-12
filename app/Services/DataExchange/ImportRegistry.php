<?php

namespace App\Services\DataExchange;

use App\Services\DataExchange\Contracts\ImportHandler;
use InvalidArgumentException;

/**
 * Phase 17 §17.1 : registre central d'imports. Délègue au
 * `DefinitionRegistry` existant (zéro duplication) ; tout nouveau
 * module s'y branche via une définition, sans toucher au cœur.
 */
final class ImportRegistry
{
    public function __construct(private readonly DefinitionRegistry $definitions) {}

    /** @return array<int, string> */
    public function entites(): array
    {
        return $this->definitions->entites();
    }

    public function handler(string $entite): ImportHandler
    {
        try {
            $definition = $this->definitions->import($entite);
        } catch (InvalidArgumentException $e) {
            throw $e;
        }

        return new DefinitionImportHandlerAdapter($entite, $definition);
    }
}
