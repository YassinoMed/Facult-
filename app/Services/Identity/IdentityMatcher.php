<?php

namespace App\Services\Identity;

/**
 * Comparaison INTELLIGENTE OCR ↔ base de données (§15-16) :
 * jamais `$ocrName === $databaseName` brut.
 *
 * Pipeline de comparaison :
 *   normalisation Unicode → normalisation arabe (diacritiques محمّد≡محمد)
 *   → translittération arabe→latin si nécessaire → suppression
 *   ponctuation/espaces multiples → casse ignorée → comparaison.
 *
 * Trois verdicts :
 *   - 'identique'  : égal après normalisation (ben علي ≡ BEN ALI) ;
 *   - 'similaire'  : distance d'édition ≤ tolérance OCR (Mohamed vs
 *                    Mohammed) → signalé pour vérification humaine ;
 *   - 'different'  : écart réel → divergence à vérifier.
 *
 * L'arabe est TRANSLITTÉRÉ pour la comparaison, jamais traduit (§7).
 */
final class IdentityMatcher
{
    public const IDENTIQUE = 'identique';

    public const SIMILAIRE = 'similaire';

    public const DIFFERENT = 'different';

    public const INCONNU = 'inconnu';

    public function __construct(private readonly ArabicTransliterationService $transliteration) {}

    /**
     * Compare une valeur OCR (arabe, latine ou mixte) à la valeur BD.
     */
    public function comparer(?string $ocr, ?string $bd): string
    {
        if ($ocr === null || $ocr === '' || $bd === null || $bd === '') {
            return self::INCONNU;
        }

        $ocrNormalise = $this->cleDeComparaison($ocr);
        $bdNormalise = $this->cleDeComparaison($bd);

        if ($ocrNormalise === '' || $bdNormalise === '') {
            return self::INCONNU;
        }

        if ($ocrNormalise === $bdNormalise) {
            return self::IDENTIQUE;
        }

        // Tolérance OCR (§17) : 1 faute de lecture admise, 2 au-delà
        // de 10 caractères — au-delà, la différence est réelle.
        $tolerance = mb_strlen($bdNormalise) >= 10 ? 2 : 1;

        return levenshtein($ocrNormalise, $bdNormalise) <= $tolerance
            ? self::SIMILAIRE
            : self::DIFFERENT;
    }

    /**
     * Les valeurs correspondent-elles SANS divergence à signaler ?
     */
    public function correspondent(?string $ocr, ?string $bd): bool
    {
        return $this->comparer($ocr, $bd) === self::IDENTIQUE;
    }

    /**
     * Clé de comparaison (§16) : normalisation arabe si besoin →
     * translittération → latin majuscule sans ponctuation ni
     * espaces multiples.
     */
    private function cleDeComparaison(string $valeur): string
    {
        if (ArabicNormalizer::contientArabe($valeur)) {
            $valeur = $this->transliteration->transliterate($valeur);
        }

        $valeur = ArabicNormalizer::normaliserPourComparaison($valeur);

        // Latin : accents pliés (É → E) pour une comparaison stable
        $valeur = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valeur) ?: $valeur;
        $valeur = (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($valeur));
        $valeur = (string) preg_replace('/\s+/', ' ', $valeur);

        return trim($valeur);
    }
}
