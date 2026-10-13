<?php

namespace App\Services\Io;

/**
 * Normalisation UNIQUE des clés d'en-tête (PHASE 2) : les 5 parsers Io et
 * `DataImportService::normaliser()` partageaient 7 copies divergentes qui
 * supprimaient les accents (« Prénom » → « prnom », jamais mappé).
 * Translittération (convention `IdentityMatcher`) puis minuscules,
 * séparateurs → `_`, caractères spéciaux supprimés. Pure, déterministe.
 */
final class NormaliseurEnTetes
{
    public static function cle(string $texte): string
    {
        $texte = trim($texte);

        // Carte EXPLICITE des exposants/symboles dont la translittération
        // iconv VARIE selon la bibliothèque système (libiconv macOS : °→0 ;
        // glibc Ubuntu/CI : caractère supprimé — run CI 38347795151) : la
        // clé doit être déterministe sur toute plateforme. Les exposants
        // unicode deviennent leur chiffre (N°CIN → n0cin, 1ᵉʳ → 1er).
        $texte = str_replace(
            ["\u{00B0}", "\u{00B9}", "\u{00B2}", "\u{00B3}"],
            ['0', '1', '2', '3'],
            $texte
        );

        // Translittération vers l'ASCII (é→e, ç→c…) avant tout
        // filtrage : sans elle les lettres accentuées disparaissaient.
        $translittere = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texte);

        $cle = mb_strtolower($translittere !== false ? $translittere : $texte);
        $cle = str_replace([' ', '-', '.'], '_', $cle);

        return (string) preg_replace('/[^a-z0-9_]/', '', $cle);
    }
}
