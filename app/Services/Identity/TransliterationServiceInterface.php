<?php

namespace App\Services\Identity;

/**
 * Translittération arabe → latin (§8) : contrat dédié, stratégies
 * interchangeables (norme tunisienne francophone, DIN 31635, ISO 233…).
 *
 * Règle fondamentale : les noms propres ne sont JAMAIS traduits
 * sémantiquement (محمد ≠ « le loué ») — uniquement TRANSLITTÉRÉS.
 */
interface TransliterationServiceInterface
{
    /**
     * Translittère un texte arabe (ou mixte) en écriture latine.
     * Les parties déjà latines sont conservées telles quelles.
     */
    public function transliterate(string $texte): string;
}
