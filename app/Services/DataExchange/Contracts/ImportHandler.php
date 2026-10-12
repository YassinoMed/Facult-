<?php

namespace App\Services\DataExchange\Contracts;

/**
 * Phase 17 §17.1 : contrat d'import par entité. Adapté sur les
 * `ImportDefinitionInterface` existantes (non-duplication) : toute
 * définition enregistrée dans `DefinitionRegistry` est exposée comme
 * `ImportHandler` via `DefinitionImportHandlerAdapter`.
 */
interface ImportHandler
{
    public function entite(): string;

    /** @return array<int, string> */
    public function champs(): array;

    /** @return array<int, string> */
    public function champsRequis(): array;

    /** @return array<int, string> clé de correspondance hiérarchique */
    public function cleCorrespondance(): array;

    /** @return array<string, string> */
    public function regles(): array;

    /** @return array<string> */
    public function modes(): array;
}
