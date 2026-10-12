<?php

namespace App\Services\Identity;

/**
 * Normalisation arabe (§3/§16) — TOUTE comparaison de texte arabe passe
 * ici avant d'être considérée. L'OCR arabe produit des variantes qui
 * doivent être neutralisées de façon DÉTERMINISTE :
 *
 *   محمّد  →  محمد   (diacritiques/tashkeel supprimés)
 *   بنـتي  →  بنتي   (tatweel supprimé)
 *   أحمد   →  احمد   (variantes d'alef unifiées)
 *   على    →  علي    (alif maqsura → yaa)
 *   ١٥/٠٢/٢٠٠٢  →  15/02/2002  (chiffres arabo-indiques → latins)
 */
final class ArabicNormalizer
{
    /** Langues détectées (§2). */
    public const LANGUE_ARABE = 'ar';

    public const LANGUE_LATIN = 'latin';

    public const LANGUE_MIXTE = 'mixte';

    /** Diacritiques (tashkeel) + signes coraniques à supprimer. */
    private const TASHKEEL = '/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06DC}\x{06DF}-\x{06E8}\x{06EA}-\x{06ED}]/u';

    /** Tatweel (allongement ـ) et caractères de contrôle arabes. */
    private const TATWEEL = '/[\x{0640}\x{0640}]/u';

    /**
     * Le texte contient-il des caractères arabes ?
     */
    public static function contientArabe(string $texte): bool
    {
        return (bool) preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $texte);
    }

    /**
     * Détection de la langue du document (§2) :
     * 'ar' = arabe uniquement, 'latin' = latin uniquement,
     * 'mixte' = les deux (cas courant de la CIN tunisienne :
     * العربية + chiffres latins + mentions latines).
     */
    public static function detecterLangue(string $texte): string
    {
        $arabe = self::contientArabe($texte);

        // Lettres latines A-Z (exclut chiffres et ponctuation)
        $latin = (bool) preg_match('/[A-Za-z]/', $texte);

        return match (true) {
            $arabe && $latin => self::LANGUE_MIXTE,
            $arabe => self::LANGUE_ARABE,
            $texte !== '' => self::LANGUE_LATIN,
            default => '',
        };
    }

    /**
     * Chiffres arabo-indiques (٠-٩) et persans (۰-۹) → chiffres latins.
     * Appliqué AVANT toute extraction de numéro ou de date (§5/§6).
     */
    public static function versChiffresLatins(string $texte): string
    {
        $correspondance = [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ];

        return strtr($texte, $correspondance);
    }

    /**
     * Normalisation arabe complète (§16) :
     * Unicode NFC → suppression diacritiques/tatweel → unification
     * des variantes de lettres (alef, yaa, taa marbuta, hamza) →
     * chiffres latins → ponctuation arabe neutralisée → espaces réduits.
     *
     * Le résultat sert UNIQUEMENT aux comparaisons et à la
     * translittération — la donnée brute est TOUJOURS conservée (§9).
     */
    public static function normaliser(string $texte): string
    {
        $texte = \Normalizer::normalize($texte, \Normalizer::NFC) ?: $texte;
        $texte = self::versChiffresLatins($texte);
        $texte = (string) preg_replace(self::TASHKEEL, '', $texte);
        $texte = (string) preg_replace(self::TATWEEL, '', $texte);

        // Variantes de lettres — la table de correspondance est
        // appliquée sur des plages Unicode précises (jamais en dur
        // sur des octets bruts) :
        $texte = strtr($texte, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', // alef
            'ى' => 'ي',                                        // alif maqsura → yaa
            'ؤ' => 'و',                                        // waw hamza
            'ئ' => 'ي',                                        // yaa hamza
            'ﻻ' => 'لا', 'ﻼ' => 'لا',                          // ligature lam-alef
            '،' => ',', '؛' => ';', '؟' => '?',                // ponctuation arabe
        ]);

        // Espaces multiples + espaces insécables → un espace simple
        $texte = (string) preg_replace('/[\s\x{00A0}\x{200B}-\x{200D}]+/u', ' ', $texte);

        return trim($texte);
    }

    /**
     * Normalisation dédiée à la COMPARAISON (§16) : tout ce qui est
     * non significatif pour un nom propre est neutralisé :
     * taa marbuta ة→ه, hamza seule supprimée, ponctuation et
     * tirets supprimés, casse latine ignorée.
     */
    public static function normaliserPourComparaison(string $texte): string
    {
        $texte = self::normaliser($texte);
        $texte = strtr($texte, ['ة' => 'ه', 'ء' => '']);

        // Ponctuation / tirets / apostrophes (latins et arabes)
        $texte = (string) preg_replace('/[\'\-\–\—_,;:!?()."«»…*\/]/u', '', $texte);

        return mb_strtoupper($texte);
    }

    /**
     * Deux textes arabes (ou mixtes) sont-ils équivalents après
     * normalisation ? (محمّد ≡ محمد, على ≡ علي…)
     */
    public static function sontEquivalents(string $a, string $b): bool
    {
        return self::normaliserPourComparaison($a) === self::normaliserPourComparaison($b);
    }
}
