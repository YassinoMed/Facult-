<?php

namespace App\Modules\Schedule\Import;

/**
 * Résultat de validation/prévisualisation (§11/§19) : lignes valides,
 * erreurs bloquantes, avertissements, doublons — jamais de PII
 * superflue (références métier uniquement).
 */
final readonly class ImportResult
{
    /**
     * @param  array<int, array<string, mixed>>  $valides
     * @param  array<int, array{ligne: int|string, champ: string, message: string}>  $erreurs
     * @param  array<int, string>  $avertissements
     * @param  array<int, array<string, mixed>>  $apercu
     * @param  array{matieres: int, enseignants: int, salles: int}  $resolues
     */
    public function __construct(
        public array $valides = [],
        public array $erreurs = [],
        public array $avertissements = [],
        public array $apercu = [],
        public ?array $existant = null,
        public array $resolues = ['matieres' => 0, 'enseignants' => 0, 'salles' => 0],
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
