<?php

namespace App\Services\Identity;

/**
 * Dictionnaire des libellés arabes des CIN tunisiennes (§4) :
 * identifie un libellé OCR arabe → champ métier, avec variantes
 * d'écriture. EXTENSIBLE : ajouter une variante dans les listes
 * ci-dessous suffit — aucune logique à modifier.
 *
 * Les libellés sont comparés après normalisation arabe
 * (ArabicNormalizer::sontEquivalents) : diacritiques et variantes
 * d'écriture sont tolérés automatiquement.
 */
final class ArabicLabelDictionary
{
    /**
     * @var array<string, array<int, string>> champ → libellés arabes possibles
     */
    private const LIBELLES = [
        'document_number' => [
            'رقم بطاقة التعريف الوطنية',
            'رقم بطاقة التعريف',
            'بطاقة التعريف الوطنية',
            'رقم البطاقة',
            'الرقم الوطني',
        ],
        'last_name' => [
            'اللقب',
            'النسب',
            'لقب',
        ],
        'first_name' => [
            'الاسم',
            'الأسماء',
            'الاسم الشخصي',
            'اسم',
        ],
        // Libellé COMBINÉ fréquent : « الاسم و اللقب : بن علي محمد »
        'full_name' => [
            'الاسم واللقب',
            'الاسم و اللقب',
            'الاسم والنسب',
        ],
        'date_of_birth' => [
            'تاريخ الولادة',
            'تاريخ الميلاد',
            'المولود في',
            // Variantes OCR (ة→ه tolérées par la normalisation ; « ولاده »
            // ≡ « ولادة » via ArabicNormalizer::normaliserPourComparaison)
            'تاريخ الولاده',
            'تاريخ المولد',
        ],
        'place_of_birth' => [
            // §6 : libellé OFFICIEL du recto CIN tunisienne (arabe)
            'مكانها',
            'مكان الولادة',
            'محل الولادة',
            'مكان الميلاد',
            'محل الميلاد',
            'مكان الولاده',
        ],
        'nationality' => [
            'الجنسية',
        ],
        'sex' => [
            'الجنس',
        ],
    ];

    /** Valeurs arabes du champ sexe. */
    private const SEXE_MASCULIN = ['ذكر', 'مذكر'];

    private const SEXE_FEMININ = ['أنثى', 'انثي', 'انثى', 'مؤنث'];

    /**
     * Le libellé (début de ligne, ponctuation retirée) correspond-il à
     * un champ connu ? Retourne le champ métier, sinon null.
     */
    public static function champPourLibelle(string $libelle): ?string
    {
        $libelleNormalise = ArabicNormalizer::normaliser($libelle);

        foreach (self::LIBELLES as $champ => $variantes) {
            foreach ($variantes as $variante) {
                if (ArabicNormalizer::sontEquivalents($libelleNormalise, $variante)) {
                    return $champ;
                }
            }
        }

        return null;
    }

    /**
     * Le champ « الجنس » est-il suivi d'une valeur arabe reconnaissable ?
     * Retourne M | F | null.
     */
    public static function sexePourValeur(string $valeur): ?string
    {
        $valeurNormalisee = ArabicNormalizer::normaliser($valeur);

        foreach (self::SEXE_MASCULIN as $masculin) {
            if (ArabicNormalizer::sontEquivalents($valeurNormalisee, $masculin)) {
                return 'M';
            }
        }

        foreach (self::SEXE_FEMININ as $feminin) {
            if (ArabicNormalizer::sontEquivalents($valeurNormalisee, $feminin)) {
                return 'F';
            }
        }

        // Tolérance OCR : préfixes univoques
        if (mb_strpos($valeurNormalisee, 'ذكر') === 0) {
            return 'M';
        }
        if (mb_strpos($valeurNormalisee, 'انث') === 0 || mb_strpos($valeurNormalisee, 'أنث') === 0) {
            return 'F';
        }

        return null;
    }
}
