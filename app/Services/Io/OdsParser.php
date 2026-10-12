<?php

namespace App\Services\Io;

use Illuminate\Http\UploadedFile;
use OpenSpout\Reader\ODS\Options;
use OpenSpout\Reader\ODS\Reader;

/**
 * Parser ODS via OpenSpout (lecture en flux, même garde-fous que
 * XlsxParser : 1re feuille, plafonds lignes/colonnes, pas de formules).
 */
class OdsParser implements ParsesFiles
{
    public function format(): string
    {
        return 'ods';
    }

    public function parse(UploadedFile $fichier): array
    {
        $cheminTemporaire = $fichier->getRealPath() ?: $fichier->getPathname();
        $maxLignes = max(1, (int) config('integrations.import_max_rows', 10000));

        $options = new Options;
        $reader = new Reader($options);

        $lignes = [];
        $entetes = null;

        $reader->open($cheminTemporaire);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $ligne) {
                    if ($ligne->isEmpty()) {
                        continue;
                    }

                    $cellules = [];

                    foreach ($ligne->toArray() as $valeur) {
                        $cellules[] = (string) $valeur;
                    }

                    if ($entetes === null) {
                        $entetes = array_map(fn ($h) => $this->normaliserCle($h), $cellules);

                        if (count($entetes) > 200) {
                            throw IoException::malforme('le fichier dépasse la limite de 200 colonnes.');
                        }

                        continue;
                    }

                    if (count($lignes) >= $maxLignes) {
                        throw IoException::malforme("le fichier dépasse la limite de {$maxLignes} lignes de données.");
                    }

                    $cellules = array_pad($cellules, count($entetes), '');
                    $lignes[] = array_slice(array_combine($entetes, array_map('trim', $cellules)), 0, count($entetes));
                }

                break; // première feuille uniquement
            }
        } finally {
            $reader->close();
        }

        if ($entetes === null || $lignes === []) {
            throw IoException::malforme('le fichier ODS doit contenir des en-têtes et au moins une ligne.');
        }

        return $lignes;
    }

    private function normaliserCle(string $cle): string
    {
        return NormaliseurEnTetes::cle($cle) ?: 'colonne_'.uniqid();
    }
}
