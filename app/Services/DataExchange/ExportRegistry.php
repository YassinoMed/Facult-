<?php

namespace App\Services\DataExchange;

use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use InvalidArgumentException;

/**
 * Phase 17 §17.1 : registre central d'exports (délègue au registre
 * existant). Masquage des colonnes sensibles via `export.sensitive`.
 */
final class ExportRegistry
{
    public function __construct(private readonly DefinitionRegistry $definitions) {}

    public function definition(string $entite): ExportDefinitionInterface
    {
        try {
            return $this->definitions->export($entite);
        } catch (InvalidArgumentException $e) {
            throw $e;
        }
    }

    /**
     * @param  array<int, string>  $colonnes
     * @return array<int, string>
     */
    public function colonnesAutorisees(string $entite, array $colonnes, bool $peutVoirSensible): array
    {
        $sensibles = ['cin', 'telephone', 'adresse', 'date_naissance'];

        if ($peutVoirSensible) {
            return $colonnes;
        }

        return array_values(array_diff($colonnes, $sensibles));
    }
}
