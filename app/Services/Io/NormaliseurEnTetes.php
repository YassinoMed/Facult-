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

        // Translittération vers l'ASCII (é→e, ç→c, °→o…) avant tout
        // filtrage : sans elle les lettres accentuées disparaissaient.
        $translittere = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texte);

        $cle = mb_strtolower($translittere !== false ? $translittere : $texte);
        $cle = str_replace([' ', '-', '.'], '_', $cle);

        return (string) preg_replace('/[^a-z0-9_]/', '', $cle);
    }
}
