<?php

namespace App\Services\Io;

use Illuminate\Http\UploadedFile;

/**
 * Parser CSV — détection du séparateur, BOM UTF-8, en-tête obligatoire.
 */
class CsvParser implements ParsesFiles
{
    /** Plafond anti-DoS mémoire : lignes de données et colonnes. */
    public const COLONNES_MAX = 200;

    public function format(): string
    {
        return 'csv';
    }

    public function parse(UploadedFile $fichier): array
    {
        $contenu = $fichier->getContent();

        // Retrait du BOM éventuel (exports Windows)
        if (str_starts_with($contenu, "\xEF\xBB\xBF")) {
            $contenu = substr($contenu, 3);
        }

        $lignes = array_filter(explode("\n", str_replace("\r\n", "\n", $contenu)), fn ($l) => trim($l) !== '');

        if (count($lignes) < 2) {
            throw IoException::malforme('le CSV doit contenir une ligne d\'en-tête et au moins une ligne de données.');
        }

        // Plafond AVANT boucle : un CSV de 10 Mo tient en RAM, mais des
        // centaines de milliers de lignes épuiseraient le worker en aval.
        $maxLignes = max(1, (int) config('integrations.import_max_rows', 10000));

        if (count($lignes) - 1 > $maxLignes) {
            throw IoException::malforme("le fichier dépasse la limite de {$maxLignes} lignes de données.");
        }

        $separateur = str_contains($lignes[0], ';') && substr_count($lignes[0], ';') >= substr_count($lignes[0], ',')
            ? ';'
            : ',';

        $entetes = array_map(fn ($h) => $this->normaliserCle($h), str_getcsv((string) array_shift($lignes), $separateur, '"', '\\'));

        if (count($entetes) > self::COLONNES_MAX) {
            throw IoException::malforme('le fichier dépasse la limite de '.self::COLONNES_MAX.' colonnes.');
        }

        $donnees = [];
        foreach ($lignes as $index => $ligne) {
            $valeurs = str_getcsv($ligne, $separateur, '"', '\\');

            if (count($valeurs) !== count($entetes)) {
                throw IoException::malforme('ligne '.($index + 2).' : nombre de colonnes incohérent avec l\'en-tête.');
            }

            $donnees[] = array_combine($entetes, array_map(fn ($v) => trim((string) $v), $valeurs));
        }

        return $donnees;
    }

    private function normaliserCle(string $cle): string
    {
        return NormaliseurEnTetes::cle($cle) ?: $cle;
    }
}
