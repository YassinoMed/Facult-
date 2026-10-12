<?php

namespace App\Services\DataExchange;

/**
 * Résultat d'analyse d'import (§10-13) : partagé web/API.
 */
final readonly class ImportResult
{
    /**
     * @param  array<int, array<string, mixed>>  $valides
     * @param  array<int, array{ligne: int|string, champ: string, message: string, code: string}>  $erreurs
     * @param  array<int, string>  $avertissements
     * @param  array<int, array<string, mixed>>  $apercu
     * @param  array<int, array{ligne: int|string, champ: string, originale: string, proposee: string, regle: string}>  $corrections
     * @param  array<int, array{ligne: int|string, champ: string, valeur: string, regle: string, raison: string}>  $aRevoir
     */
    public function __construct(
        public array $valides = [],
        public array $erreurs = [],
        public array $avertissements = [],
        public array $apercu = [],
        public array $corrections = [],
        public array $aRevoir = [],
    ) {}

    public function total(): int
    {
        return count($this->valides) + count($this->erreurs);
    }

    public function bloque(): bool
    {
        return $this->erreurs !== [];
    }
}
