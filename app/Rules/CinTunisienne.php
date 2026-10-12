<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validation stricte du format de la CIN tunisienne (§3) :
 * - Exactement 8 chiffres ASCII [0-9].
 * - Les zéros initiaux sont préservés en chaîne ('01234567').
 * - Refuse les lettres, espaces, tirets et autres caractères.
 * - Ne convertit jamais silencieusement une valeur invalide en CIN valide.
 */
class CinTunisienne implements ValidationRule
{
    public const PATTERN = '/\A[0-9]{8}\z/';

    /**
     * Exécute la règle de validation.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match(self::PATTERN, $value)) {
            $fail("Le champ :attribute doit être un numéro de CIN tunisienne valide composé d'exactement 8 chiffres (chiffres 0 à 9 uniquement, sans lettre, espace ou tiret).");
        }
    }
}
