<?php

namespace App\Services\Io;

/**
 * Résultat d'analyse d'un fichier d'import (phase prévisualisation, §37) :
 * compteurs, lignes valides prêtes à être importées, erreurs détaillées,
 * doublons et avertissements. Ne contient JAMAIS de secret.
 */
class ImportPreview
{
    /**
     * @param  array<int, array<string, mixed>>  $lignesValides
     * @param  array<int, array{ligne: int, champ: string, message: string}>  $erreurs
     * @param  array<int, string>  $avertissements
     */
    public function __construct(
        public readonly array $lignesValides,
        public readonly array $erreurs,
        public readonly array $avertissements,
    ) {}

    public function total(): int
    {
        return $this->valides() + $this->doublons() + $this->enErreur();
    }

    public function valides(): int
    {
        return count($this->lignesValides);
    }

    public function doublons(): int
    {
        return count($this->avertissements);
    }

    /**
     * Lignes en erreur (une ligne avec 5 champs invalides = 1 erreur).
     */
    public function enErreur(): int
    {
        return count(array_unique(array_column($this->erreurs, 'ligne')));
    }

    /**
     * Résumé sérialisable stocké dans le rapport (échantillon d'erreurs).
     *
     * @return array<string, mixed>
     */
    public function tableau(): array
    {
        return [
            'total' => $this->total(),
            'valides' => $this->valides(),
            'doublons' => $this->doublons(),
            'erreurs' => $this->enErreur(),
            'detail_erreurs' => array_slice($this->erreurs, 0, 50),
            'avertissements' => array_slice($this->avertissements, 0, 50),
        ];
    }
}
