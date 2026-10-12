<?php

namespace App\Services\Recherche;

/**
 * Phase 18 §18.3 : normalisation tolérante (casse, accents,
 * ponctuation, Unicode NFC, chiffres arabo-indiens ↔ latins, alef /
 * hamza / yaa / taa marbouta, tatweel et tashkeel ignorés) +
 * translittération arabe ↔ latin pour les noms.
 */
final class SearchNormalizer
{
    /** @var array<string, array<int, string>> */
    public const VARIANTES_NOMS = [
        'mohamed' => ['mohamed', 'mohamad', 'mohammad', 'muhammad', 'mhamed', 'med', 'moh', 'محمد'],
        'yassine' => ['yassine', 'yassin', 'yacine', 'yacin', 'ياسين'],
        'ahmed' => ['ahmed', 'ahmad', 'أحمد'],
        'karim' => ['karim', 'kariem', 'كريم'],
        'fatma' => ['fatma', 'fatima', 'فاطمة'],
    ];

    public static function normaliser(string $texte): string
    {
        $texte = normalizer_normalize($texte, \Normalizer::FORM_C) ?: $texte;
        $texte = mb_strtolower($texte, 'UTF-8');
        // Accents latins : décomposition NFD + suppression des marques
        // combinantes (é → e, sans apostrophe parasite d'iconv).
        $decompose = normalizer_normalize($texte, \Normalizer::FORM_D) ?: $texte;
        $texte = (string) preg_replace('/\p{Mn}/u', '', $decompose);
        $texte = normalizer_normalize($texte, \Normalizer::FORM_C) ?: $texte;
        // Chiffres arabo-indiens → latins.
        $texte = str_replace(
            ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            $texte
        );
        // Tashkeel + tatweel ignorés.
        $texte = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $texte);
        // Alef variantes → ا, yaa → ي, taa marbouta → ه.
        $texte = str_replace(['أ', 'إ', 'آ'], 'ا', $texte);
        $texte = str_replace(['ى'], 'ي', $texte);
        $texte = str_replace(['ة'], 'ه', $texte);
        $texte = (string) preg_replace('/[^\p{L}\p{N}\s\-]/u', ' ', $texte);
        $texte = (string) preg_replace('/\s+/', ' ', $texte);

        return trim($texte);
    }

    /**
     * @return array<int, string> variantes de recherche pour un terme.
     */
    public static function variantes(string $terme): array
    {
        $normalise = self::normaliser($terme);
        $variantes = [$normalise];

        foreach (self::VARIANTES_NOMS as $canonique => $formes) {
            if (in_array($normalise, $formes, true)) {
                $variantes = array_merge($variantes, $formes);
            }
        }

        // Translittération inverse : si le terme est arabe, ajouter les
        // formes latines connues (et inversement).
        return array_values(array_unique($variantes));
    }

    /**
     * Échappe les opérateurs du moteur (aucune injection §18.5/4).
     */
    public static function echapperOperateurs(string $requete): string
    {
        return addcslashes($requete, '*?()"\''.'\\');
    }
}
